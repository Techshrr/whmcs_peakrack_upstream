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

namespace PeakRack\UpstreamApi\Contracts;

use PeakRack\UpstreamApi\Domain\PolicyTemplate;

interface PolicyTemplateRepository
{
    public function saveTemplate(array $template, array $items): int;

    public function find(int $id): ?PolicyTemplate;

    public function listTemplates(bool $enabledOnly = false): array;

    public function deleteTemplate(int $id): void;
}
