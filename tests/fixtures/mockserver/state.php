<?php

final class PeakRackMockState
{
    public function __construct(private readonly string $path)
    {
    }

    public function read(): array
    {
        return $this->withLock(static fn (array $state): array => $state, false);
    }

    public function update(callable $callback): array
    {
        return $this->withLock($callback, true);
    }

    private function withLock(callable $callback, bool $write): array
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open mock server state.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock mock server state.');
            }
            rewind($handle);
            $contents = stream_get_contents($handle);
            $decoded = is_string($contents) && $contents !== ''
                ? json_decode($contents, true)
                : null;
            $state = is_array($decoded) ? $decoded : self::initial();
            $result = $callback($state);
            if (!is_array($result)) {
                throw new RuntimeException('Mock server state callback returned invalid data.');
            }

            if ($write) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                fflush($handle);
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function initial(): array
    {
        return [
            'valid_requests' => 0,
            'invalid_signatures' => 0,
            'create_executions' => 0,
            'operations' => [],
            'services' => [],
        ];
    }
}

final class PeakRackMockServerHarness
{
    private const API_PATH = '/modules/addons/peakrack_upstream_api/api/v1';

    private mixed $process = null;
    private array $pipes = [];
    private readonly int $port;
    private readonly string $statePath;

    public function __construct()
    {
        if (!function_exists('curl_init') || !function_exists('proc_open')) {
            throw new RuntimeException('The integration contract tests require cURL and proc_open.');
        }

        $this->port = $this->availablePort();
        $this->statePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'peakrack-upstream-contract-'
            . bin2hex(random_bytes(8))
            . '.json';
        $command = [
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $this->port,
            '-t',
            __DIR__,
            __DIR__ . '/router.php',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['PEAKRACK_MOCK_STATE'] = $this->statePath;
        $environment['PEAKRACK_MOCK_KEY'] = 'public-key';
        $environment['PEAKRACK_MOCK_SECRET'] = 'api-secret';

        $this->process = proc_open(
            $command,
            $descriptors,
            $this->pipes,
            __DIR__,
            $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($this->process)) {
            throw new RuntimeException('Unable to start mock API server.');
        }
        fclose($this->pipes[0]);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }

        $error = trim((string) stream_get_contents($this->pipes[2]));
        $this->stop();
        throw new RuntimeException('Mock API server did not start. ' . $error);
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function baseUrl(): string
    {
        return 'https://127.0.0.1:' . $this->port . self::API_PATH;
    }

    public function state(): array
    {
        return (new PeakRackMockState($this->statePath))->read();
    }

    public function transport(array $request): array
    {
        $url = preg_replace('#^https://#', 'http://', (string) ($request['url'] ?? ''));
        if (!is_string($url) || !str_starts_with($url, 'http://127.0.0.1:' . $this->port . '/')) {
            throw new RuntimeException('The contract transport only allows its local mock server.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize contract cURL request.');
        }
        $headers = [];
        foreach ((array) ($request['headers'] ?? []) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $timeout = max(1, min(10, (int) ($request['options']['timeout'] ?? 5)));
        $options = [
            CURLOPT_CUSTOMREQUEST => (string) ($request['method'] ?? 'GET'),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
        ];
        if ((string) ($request['body'] ?? '') !== '') {
            $options[CURLOPT_POSTFIELDS] = (string) $request['body'];
        }
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        if (!is_string($body)) {
            curl_close($handle);
            throw new RuntimeException('The contract cURL request failed.');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => $body];
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $status = proc_get_status($this->process);
                if (!is_array($status) || ($status['running'] ?? false) !== true) {
                    break;
                }
                usleep(50000);
            }
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
            $this->process = null;
            $this->pipes = [];
        }

        if (isset($this->statePath) && is_file($this->statePath)) {
            unlink($this->statePath);
        }
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (!is_resource($socket)) {
            throw new RuntimeException('Unable to allocate a mock API port.');
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = is_string($name) ? (int) substr(strrchr($name, ':'), 1) : 0;
        if ($port < 1) {
            throw new RuntimeException('Unable to determine the mock API port.');
        }

        return $port;
    }
}
