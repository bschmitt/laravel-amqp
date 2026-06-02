<?php

namespace Bschmitt\Amqp\Test\Unit\Http;

use Bschmitt\Amqp\Http\Controllers\HealthController;
use Bschmitt\Amqp\Support\HealthCheck;
use Bschmitt\Amqp\Support\HealthState;
use PHPUnit\Framework\TestCase;

class HealthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HealthState::reset();
    }

    protected function tearDown(): void
    {
        HealthState::reset();
        parent::tearDown();
    }

    public function testLivenessReturns200WhenAlive(): void
    {
        $state = new HealthState();
        $state->markReady();
        $controller = new HealthController(new HealthCheck($state));

        $response = $controller->liveness();
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame('liveness', $payload['kind']);
    }

    public function testLivenessReturns503WhenDead(): void
    {
        $state = new HealthState();
        $state->markDead('crashed');
        $controller = new HealthController(new HealthCheck($state));

        $response = $controller->liveness();
        $this->assertSame(503, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertFalse($payload['ok']);
    }

    public function testReadinessReturns200WhenReady(): void
    {
        $state = new HealthState();
        $state->markReady();
        $controller = new HealthController(new HealthCheck($state));

        $response = $controller->readiness();
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertTrue($payload['ok']);
    }

    public function testReadinessReturns503WhenNotReady(): void
    {
        $state = new HealthState();
        $controller = new HealthController(new HealthCheck($state));

        $response = $controller->readiness();
        $this->assertSame(503, $response->getStatusCode());
    }

    public function testSnapshotIncludesBothProbes(): void
    {
        $state = new HealthState();
        $state->markReady();
        $controller = new HealthController(new HealthCheck($state));

        $response = $controller->snapshot();
        $payload = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('liveness', $payload);
        $this->assertArrayHasKey('readiness', $payload);
        $this->assertArrayHasKey('state', $payload);
    }
}
