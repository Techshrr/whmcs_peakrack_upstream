<?php

define('PEAKRACK_UPSTREAM_ROOT', dirname(__DIR__));

require_once __DIR__ . '/TestCase.php';

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'PeakRack\\UpstreamApi\\' => PEAKRACK_UPSTREAM_ROOT . '/modules/addons/peakrack_upstream_api/lib/',
        'PeakRack\\Upstream\\' => PEAKRACK_UPSTREAM_ROOT . '/modules/servers/peakrackupstream/lib/',
    ];

    foreach ($prefixes as $prefix => $baseDirectory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $path = $baseDirectory . $relative . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    }
});
