<?php

namespace PeakRack\Tests\Unit\Upstream;

use LogicException;
use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Domain\Operation;

final class OperationTest extends TestCase
{
    public function testMovesThroughACompletedLifecycle(): void
    {
        $operation = Operation::admit('op-1', 7, 'create', 'idem-1', 'hash-1');
        $this->assertSame('queued', $operation->status());

        $operation = $operation->start();
        $this->assertSame('processing', $operation->status());

        $operation = $operation->complete(['upstream_service_id' => 99]);
        $this->assertSame('completed', $operation->status());
        $this->assertSame(['upstream_service_id' => 99], $operation->result());
    }

    public function testAllowsFailureAndManualReviewFromProcessing(): void
    {
        $operation = Operation::admit('op-1', 7, 'create', 'idem-1', 'hash-1')->start();

        $failed = $operation->fail('PROVISIONING_FAILED', 'Provider rejected create.');
        $this->assertSame('failed', $failed->status());
        $this->assertSame('PROVISIONING_FAILED', $failed->errorCode());

        $manual = $operation->manualReview('MANUAL_REVIEW_REQUIRED', 'Check provider state.');
        $this->assertSame('manual_review', $manual->status());
    }

    public function testTerminalOperationCannotTransition(): void
    {
        $operation = Operation::admit('op-1', 7, 'create', 'idem-1', 'hash-1')
            ->start()
            ->complete([]);

        $this->assertThrows(
            static fn () => $operation->fail('PROVISIONING_FAILED', 'Too late.'),
            LogicException::class,
            'terminal'
        );
    }
}
