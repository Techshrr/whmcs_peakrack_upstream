<?php

namespace PeakRack\Tests\Integration;

use RuntimeException;

final class WhmcsTestSupport
{
    private static bool $booted = false;
    private static ?string $root = null;

    public static function enabled(): bool
    {
        return getenv('PEAKRACK_WHMCS_TEST') === '1';
    }

    public static function boot(): string
    {
        $root = self::root();
        if (!self::$booted) {
            $bootstrapCompleted = false;
            register_shutdown_function(static function () use (&$bootstrapCompleted): void {
                if (!$bootstrapCompleted) {
                    fwrite(STDERR, "\nWHMCS bootstrap terminated before integration tests could run.\n");
                    exit(1);
                }
            });
            require_once $root . '/init.php';
            $bootstrapCompleted = true;
            self::$booted = true;
        }

        return $root;
    }

    public static function root(): string
    {
        if (self::$root !== null) {
            return self::$root;
        }

        $configured = trim((string) getenv('PEAKRACK_WHMCS_ROOT'));
        if ($configured !== '') {
            return self::$root = self::validateRoot($configured);
        }

        $candidate = PEAKRACK_UPSTREAM_ROOT;
        while (true) {
            if (
                is_file($candidate . '/init.php')
                && is_dir($candidate . '/modules/addons')
                && is_dir($candidate . '/modules/servers')
            ) {
                return self::$root = str_replace('\\', '/', $candidate);
            }
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                break;
            }
            $candidate = $parent;
        }

        throw new RuntimeException('Unable to locate the WHMCS integration-test root.');
    }

    public static function version(): string
    {
        if (defined('WHMCS_VERSION')) {
            return (string) constant('WHMCS_VERSION');
        }
        if (isset($GLOBALS['CONFIG']['Version'])) {
            return (string) $GLOBALS['CONFIG']['Version'];
        }
        if (class_exists(\WHMCS\Application::class)) {
            return (string) \WHMCS\Application::getInstance()->getVersion();
        }

        return '';
    }

    public static function fileHashes(string $root): array
    {
        $root = str_replace('\\', '/', realpath($root) ?: $root);
        if (!is_dir($root)) {
            throw new RuntimeException('Module tree does not exist: ' . $root);
        }

        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($path, strlen($root)), '/');
            $hashes[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    private static function validateRoot(string $root): string
    {
        $resolved = realpath($root);
        if (
            $resolved === false
            || !is_file($resolved . '/init.php')
            || !is_dir($resolved . '/modules/addons')
            || !is_dir($resolved . '/modules/servers')
        ) {
            throw new RuntimeException('The configured WHMCS integration-test root is invalid.');
        }

        return str_replace('\\', '/', $resolved);
    }
}
