<?php
// SPDX-License-Identifier: Apache-2.0

/**
 * PeakRack Upstream WHMCS Integration
 *
 * Official repository:
 * https://github.com/Techshrr/whmcs_peakrack_upstream
 *
 * Copyright 2026 PeakRack.
 * Licensed under the Apache License, Version 2.0.
 */

namespace PeakRack\UpstreamApi\Application;

use PeakRack\UpstreamApi\Contracts\OperationExecutor as OperationExecutorContract;
use PeakRack\UpstreamApi\Domain\Operation;
use RuntimeException;

final class OperationExecutor implements OperationExecutorContract
{
    public function __construct(private readonly array $executors)
    {
    }

    public function execute(Operation $operation): Operation
    {
        return $this->executorFor($operation->action())->execute($operation);
    }

    public function verify(Operation $operation): Operation
    {
        return $this->executorFor($operation->action())->verify($operation);
    }

    private function executorFor(string $action): OperationExecutorContract
    {
        $executor = $this->executors[$action] ?? null;
        if (!$executor instanceof OperationExecutorContract) {
            throw new RuntimeException("No operation executor is registered for {$action}.");
        }

        return $executor;
    }
}
