<?php

namespace PeakRack\Tests;

use RuntimeException;

abstract class TestCase
{
    public static function group(): string
    {
        return 'unit';
    }

    protected function assertTrue(bool $condition, string $message = 'Expected condition to be true.'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Expected condition to be false.'): void
    {
        if ($condition) {
            throw new RuntimeException($message);
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $detail = sprintf(
                "Expected %s, got %s.",
                var_export($expected, true),
                var_export($actual, true)
            );

            throw new RuntimeException($message !== '' ? $message . ' ' . $detail : $detail);
        }
    }

    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            $detail = sprintf('Expected array to contain %s.', var_export($needle, true));
            throw new RuntimeException($message !== '' ? $message . ' ' . $detail : $detail);
        }
    }

    protected function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            $detail = sprintf('Expected array key %s.', var_export($key, true));
            throw new RuntimeException($message !== '' ? $message . ' ' . $detail : $detail);
        }
    }

    protected function assertFileExists(string $path, string $message = ''): void
    {
        if (!is_file($path)) {
            $detail = sprintf('Expected file to exist: %s', $path);
            throw new RuntimeException($message !== '' ? $message . ' ' . $detail : $detail);
        }
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            $detail = sprintf('Expected string to contain %s.', var_export($needle, true));
            throw new RuntimeException($message !== '' ? $message . ' ' . $detail : $detail);
        }
    }

    protected function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            $detail = sprintf('Expected string not to contain %s.', var_export($needle, true));
            throw new RuntimeException($message !== '' ? $message . ' ' . $detail : $detail);
        }
    }

    protected function assertThrows(callable $callback, string $expectedClass, ?string $messageContains = null): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            if (!$exception instanceof $expectedClass) {
                throw new RuntimeException(sprintf(
                    'Expected exception %s, got %s.',
                    $expectedClass,
                    $exception::class
                ));
            }

            if ($messageContains !== null && !str_contains($exception->getMessage(), $messageContains)) {
                throw new RuntimeException(sprintf(
                    'Expected exception message to contain %s, got %s.',
                    var_export($messageContains, true),
                    var_export($exception->getMessage(), true)
                ));
            }

            return;
        }

        throw new RuntimeException(sprintf('Expected exception %s, but none was thrown.', $expectedClass));
    }
}
