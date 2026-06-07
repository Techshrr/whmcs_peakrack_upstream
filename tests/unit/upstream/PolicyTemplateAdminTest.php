<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Admin\Csrf;
use PeakRack\UpstreamApi\Admin\OnboardingAdminController;
use PeakRack\UpstreamApi\Contracts\ApplicationRepository;
use PeakRack\UpstreamApi\Contracts\AuditRepository;
use PeakRack\UpstreamApi\Contracts\PolicyTemplateRepository;
use PeakRack\UpstreamApi\Domain\OnboardingApplication;
use PeakRack\UpstreamApi\Domain\PolicyTemplate;

final class PolicyTemplateAdminTest extends TestCase
{
    public function testAdminCanSaveTemplateWithMultipleItems(): void
    {
        $templates = new PolicyTemplateAdminFakeTemplates();
        $controller = new OnboardingAdminController(
            applications: new PolicyTemplateAdminFakeApplications(),
            templates: $templates,
            audits: new PolicyTemplateAdminFakeAudits(),
            csrf: new Csrf(static fn (): string => 'csrf-token'),
            policyValidator: static function (array $policy): void {
                if ((int) $policy['product_id'] < 1) {
                    throw new \InvalidArgumentException('The product is invalid.');
                }
            },
            clock: static fn (): int => 1780617600
        );

        $session = [];
        $token = (new Csrf(static fn (): string => 'csrf-token'))->token($session);

        $view = $controller->dispatch([
            'page' => 'policy_templates',
            'action' => 'save_template',
            'csrf_token' => $token,
            'name' => 'Starter',
            'description' => 'Starter template',
            'enabled' => '1',
            'items_json' => json_encode([
                [
                    'product_id' => 19,
                    'billing_cycles' => 'monthly',
                    'actions' => 'create,renew',
                    'locations' => '',
                    'os_templates' => '',
                    'delivery_mappings' => '',
                    'sso_hosts' => '',
                    'sso_allowed' => '0',
                    'destroy_allowed' => '0',
                ],
                [
                    'product_id' => 20,
                    'billing_cycles' => 'monthly,annually',
                    'actions' => 'create,renew,suspend',
                    'locations' => '{}',
                    'os_templates' => '{}',
                    'delivery_mappings' => '{}',
                    'sso_hosts' => '',
                    'sso_allowed' => '0',
                    'destroy_allowed' => '0',
                ],
            ], JSON_THROW_ON_ERROR),
        ], 9, $session, 'addonmodules.php?module=test');

        $this->assertSame('Policy template saved.', $view['notice']);
        $this->assertSame('Starter', $templates->savedTemplate['name']);
        $this->assertSame(2, count($templates->savedItems));
        $this->assertSame(['monthly', 'annually'], $templates->savedItems[1]['billing_cycles']);
    }
}

final class PolicyTemplateAdminFakeApplications implements ApplicationRepository
{
    public function create(array $attributes): int
    {
        return 1;
    }

    public function find(int $id): ?OnboardingApplication
    {
        return null;
    }

    public function findActiveForClient(int $clientId): ?OnboardingApplication
    {
        return null;
    }

    public function findApprovedForClient(int $clientId): ?OnboardingApplication
    {
        return null;
    }

    public function findPending(int $id): ?OnboardingApplication
    {
        return null;
    }

    public function save(OnboardingApplication $application): void
    {
    }

    public function listByStatus(?string $status, int $limit = 100): array
    {
        return [];
    }
}

final class PolicyTemplateAdminFakeTemplates implements PolicyTemplateRepository
{
    public array $savedTemplate = [];
    public array $savedItems = [];

    public function saveTemplate(array $template, array $items): int
    {
        $this->savedTemplate = $template;
        $this->savedItems = $items;

        return 1;
    }

    public function find(int $id): ?PolicyTemplate
    {
        return null;
    }

    public function listTemplates(bool $enabledOnly = false): array
    {
        return [];
    }

    public function deleteTemplate(int $id): void
    {
    }
}

final class PolicyTemplateAdminFakeAudits implements AuditRepository
{
    public array $events = [];

    public function append(array $event): void
    {
        $this->events[] = $event;
    }
}
