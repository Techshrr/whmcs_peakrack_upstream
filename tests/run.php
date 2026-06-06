<?php

require_once __DIR__ . '/bootstrap.php';

use PeakRack\Tests\TestCase;
use PeakRack\Tests\SkippedTestException;

$arguments = array_slice($argv, 1);
$group = null;
$requestedPaths = [];

for ($index = 0, $count = count($arguments); $index < $count; $index++) {
    if ($arguments[$index] === '--group') {
        $group = $arguments[$index + 1] ?? '';
        $index++;
        continue;
    }

    $requestedPaths[] = $arguments[$index];
}

$testFiles = [];

if ($requestedPaths !== []) {
    foreach ($requestedPaths as $requestedPath) {
        $resolved = realpath($requestedPath);
        if ($resolved === false || !is_file($resolved)) {
            fwrite(STDERR, "Test file not found: {$requestedPath}\n");
            exit(2);
        }
        $testFiles[] = $resolved;
    }
} else {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
            $testFiles[] = $file->getRealPath();
        }
    }
}

sort($testFiles);
$beforeClasses = get_declared_classes();

foreach ($testFiles as $testFile) {
    require_once $testFile;
}

$testClasses = array_values(array_filter(
    array_diff(get_declared_classes(), $beforeClasses),
    static fn (string $class): bool => is_subclass_of($class, TestCase::class)
));

$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($testClasses as $testClass) {
    if ($group !== null && $testClass::group() !== $group) {
        continue;
    }

    $instance = new $testClass();
    $methods = array_filter(
        get_class_methods($instance),
        static fn (string $method): bool => str_starts_with($method, 'test')
    );

    foreach ($methods as $method) {
        try {
            $instance->{$method}();
            $passed++;
            fwrite(STDOUT, "PASS {$testClass}::{$method}\n");
        } catch (SkippedTestException $exception) {
            $skipped++;
            fwrite(STDOUT, "SKIP {$testClass}::{$method}\n");
            fwrite(STDOUT, "  {$exception->getMessage()}\n");
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, "FAIL {$testClass}::{$method}\n");
            fwrite(STDERR, "  {$exception->getMessage()}\n");
        }
    }
}

fwrite(STDOUT, sprintf("\n%d passed, %d failed, %d skipped\n", $passed, $failed, $skipped));
exit($failed === 0 ? 0 : 1);
