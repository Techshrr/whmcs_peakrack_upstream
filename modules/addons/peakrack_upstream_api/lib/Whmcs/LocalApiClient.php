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

namespace PeakRack\UpstreamApi\Whmcs;

use Closure;
use InvalidArgumentException;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\ValidationException;
use Throwable;

final class LocalApiClient
{
    private const ALLOWED_COMMANDS = [
        'AcceptOrder',
        'AddCancelRequest',
        'AddCredit',
        'AddOrder',
        'ApplyCredit',
        'CancelOrder',
        'GetClientsDetails',
        'GetClientsProducts',
        'GetInvoice',
        'GetInvoices',
        'ModuleChangePackage',
        'ModuleCreate',
        'ModuleSuspend',
        'ModuleTerminate',
        'ModuleUnsuspend',
        'UpdateClientProduct',
        'UpdateInvoice',
        'UpgradeProduct',
    ];

    private readonly Closure $caller;

    public function __construct(?callable $caller = null, private readonly ?string $adminUsername = null)
    {
        $this->caller = $caller === null
            ? static function (string $command, array $parameters, ?string $adminUsername): array {
                if (!function_exists('localAPI')) {
                    throw new \RuntimeException('The WHMCS Local API is unavailable.');
                }

                return localAPI($command, $parameters, $adminUsername);
            }
            : Closure::fromCallable($caller);
    }

    public function call(string $command, array $parameters): array
    {
        if (!in_array($command, self::ALLOWED_COMMANDS, true)) {
            throw new InvalidArgumentException("The Local API command {$command} is not allowlisted.");
        }

        try {
            $response = ($this->caller)($command, $parameters, $this->adminUsername);
        } catch (Throwable) {
            throw $this->failure($command);
        }

        if (!is_array($response) || ($response['result'] ?? null) !== 'success') {
            throw $this->failure($command);
        }

        return $response;
    }

    private function failure(string $command): ValidationException
    {
        return new ValidationException(
            ApiError::INTERNAL_ERROR,
            "WHMCS Local API command {$command} failed.",
            500
        );
    }
}
