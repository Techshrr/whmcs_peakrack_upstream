<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\WorkerCommand;
use RuntimeException;

final class WorkerCliTest extends TestCase
{
    public function testWorkerRefusesNonCliExecution(): void
    {
        $executed = false;
        $stderr = [];
        $command = new WorkerCommand();

        $exit = $command->run(
            'fpm-fcgi',
            static function () use (&$executed): array {
                $executed = true;
                return [];
            },
            static function (): void {
            },
            static function (string $message) use (&$stderr): void {
                $stderr[] = $message;
            }
        );

        $this->assertSame(1, $exit);
        $this->assertFalse($executed);
        $this->assertStringContains('CLI', $stderr[0]);
    }

    public function testWorkerReturnsNonzeroSanitizedBootstrapFailure(): void
    {
        $stderr = [];
        $command = new WorkerCommand();

        $exit = $command->run(
            'cli',
            static function (): array {
                throw new RuntimeException('database password is secret');
            },
            static function (): void {
            },
            static function (string $message) use (&$stderr): void {
                $stderr[] = $message;
            }
        );

        $this->assertSame(1, $exit);
        $this->assertStringNotContains('password', $stderr[0]);
        $this->assertStringNotContains('secret', $stderr[0]);
    }
}
