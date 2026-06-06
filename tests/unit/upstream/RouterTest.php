<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Http\Request;
use PeakRack\UpstreamApi\Http\Response;
use PeakRack\UpstreamApi\Http\Router;

final class RouterTest extends TestCase
{
    public function testMatchesEveryExactStaticAndDynamicRoute(): void
    {
        $router = new Router();

        foreach ([
            ['GET', '/health', 'health'],
            ['GET', '/catalog', 'catalog'],
            ['POST', '/services', 'service.create'],
            ['GET', '/services/123', 'service.show'],
            ['POST', '/services/123/suspend', 'service.suspend'],
            ['POST', '/services/123/unsuspend', 'service.unsuspend'],
            ['POST', '/services/123/terminate', 'service.terminate'],
            ['POST', '/services/123/renew', 'service.renew'],
            ['POST', '/services/123/change-package', 'service.change_package'],
            ['POST', '/services/123/sso', 'service.sso'],
            ['GET', '/operations/123e4567-e89b-42d3-a456-426614174000', 'operation.show'],
        ] as [$method, $path, $name]) {
            $this->assertSame($name, $router->match($this->request($method, $path))['name']);
        }

        $route = $router->match($this->request('POST', '/services/123/change-package'));
        $this->assertSame('service.change_package', $route['name']);
        $this->assertSame(['local_service_id' => 123], $route['parameters']);

        $operation = $router->match(
            $this->request('GET', '/operations/123e4567-e89b-42d3-a456-426614174000')
        );
        $this->assertSame('operation.show', $operation['name']);
        $this->assertSame(
            ['operation_id' => '123e4567-e89b-42d3-a456-426614174000'],
            $operation['parameters']
        );
    }

    public function testRejectsMethodMismatchTrailingSlashAndInvalidIds(): void
    {
        $router = new Router();

        $this->assertSame(null, $router->match($this->request('GET', '/services')));
        $this->assertSame(null, $router->match($this->request('GET', '/health/')));
        $this->assertSame(null, $router->match($this->request('GET', '/services/0')));
        $this->assertSame(null, $router->match($this->request('GET', '/services/-1')));
        $this->assertSame(null, $router->match($this->request('GET', '/services/1/unknown')));
        $this->assertSame(null, $router->match($this->request('GET', '/operations/not-a-uuid')));
    }

    public function testResponseAlwaysUsesApprovedEnvelope(): void
    {
        $success = Response::success(['protocol' => 'v1'], 'completed', 'operation-id', 200);
        $failure = Response::error('RATE_LIMITED', 'Rate limit exceeded.', 429);

        $this->assertSame(200, $success->statusCode());
        $this->assertSame([
            'success' => true,
            'status' => 'completed',
            'data' => ['protocol' => 'v1'],
            'operation_id' => 'operation-id',
            'error' => null,
        ], $success->payload());
        $this->assertSame([
            'success' => false,
            'status' => 'failed',
            'data' => null,
            'operation_id' => null,
            'error' => [
                'code' => 'RATE_LIMITED',
                'message' => 'Rate limit exceeded.',
            ],
        ], $failure->payload());
        $this->assertStringContains('"success":true', $success->body());
    }

    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], '', '192.0.2.10');
    }
}
