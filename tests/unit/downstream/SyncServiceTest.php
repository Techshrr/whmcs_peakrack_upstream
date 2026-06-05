<?php

namespace PeakRack\Tests\Unit\Downstream;

use PeakRack\Tests\TestCase;
use PeakRack\Upstream\SyncCommand;
use PeakRack\Upstream\SyncService;
use RuntimeException;

final class SyncServiceTest extends TestCase
{
    public function testPrioritizesPendingIsolatesErrorsAndUpdatesConfirmedStatus(): void
    {
        $order = [];
        $applied = [];
        $updated = [];
        $sync = new SyncService(
            static fn (int $limit): array => [
                ['id' => 2, 'status' => 'Active'],
                ['id' => 3, 'status' => 'Suspended'],
                ['id' => 1, 'status' => 'Pending'],
            ],
            static function (array $service) use (&$order): array {
                $order[] = $service['id'];
                if ($service['id'] === 2) {
                    throw new RuntimeException('remote failure');
                }

                return [
                    'success' => true,
                    'status' => 'completed',
                    'data' => [
                        'service_status' => $service['id'] === 1 ? 'active' : 'suspended',
                    ],
                    'operation_id' => null,
                    'error' => null,
                ];
            },
            static function (array $service, array $response) use (&$applied): void {
                $applied[] = $service['id'];
            },
            static function (array $service, string $status) use (&$updated): void {
                $updated[] = ['id' => $service['id'], 'status' => $status];
            }
        );

        $summary = $sync->run(50);

        $this->assertSame([1, 2, 3], $order);
        $this->assertSame([1, 3], $applied);
        $this->assertSame([['id' => 1, 'status' => 'Active']], $updated);
        $this->assertSame(3, $summary['scanned']);
        $this->assertSame(2, $summary['synced']);
        $this->assertSame(1, $summary['failed']);
    }

    public function testSyncCommandRefusesBrowserExecution(): void
    {
        $executed = false;
        $stderr = [];
        $command = new SyncCommand();

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

    public function testProcessingOperationIsSyncedWithoutChangingLocalStatus(): void
    {
        $applied = [];
        $updated = [];
        $sync = new SyncService(
            static fn (int $limit): array => [['id' => 1, 'status' => 'Pending']],
            static fn (array $service): array => [
                'success' => true,
                'status' => 'processing',
                'data' => null,
                'operation_id' => '123e4567-e89b-42d3-a456-426614174000',
                'error' => null,
            ],
            static function (array $service, array $response) use (&$applied): void {
                $applied[] = $service['id'];
            },
            static function (array $service, string $status) use (&$updated): void {
                $updated[] = $status;
            }
        );

        $summary = $sync->run(50);

        $this->assertSame([1], $applied);
        $this->assertSame([], $updated);
        $this->assertSame(1, $summary['synced']);
        $this->assertSame(0, $summary['failed']);
    }
}
