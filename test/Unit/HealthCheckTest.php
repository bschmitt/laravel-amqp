<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Contracts\ConnectionManagerInterface;
use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\HealthCheck;
use Bschmitt\Amqp\Support\HealthState;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class HealthCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HealthState::reset();
    }

    protected function tearDown(): void
    {
        m::close();
        HealthState::reset();
        parent::tearDown();
    }

    public function testLivenessFailsWhenStateMarkedDead(): void
    {
        $state = new HealthState();
        $state->markDead('crashed');
        $check = new HealthCheck($state);

        $result = $check->liveness();

        $this->assertFalse($result['ok']);
        $this->assertSame('process_alive', $result['checks'][0]['name']);
        $this->assertFalse($result['checks'][0]['ok']);
    }

    public function testLivenessFailsWhenHeartbeatStale(): void
    {
        $state = new HealthState();
        $state->markReady();

        // Forcibly age the heartbeat using reflection.
        $ref = new \ReflectionClass($state);
        $prop = $ref->getProperty('lastHeartbeat');
        $prop->setAccessible(true);
        $prop->setValue($state, microtime(true) - 120.0);

        $check = (new HealthCheck($state))->maxHeartbeatAge(30.0);
        $result = $check->liveness();

        $this->assertFalse($result['ok']);
        $heartbeatCheck = $result['checks'][1];
        $this->assertSame('heartbeat', $heartbeatCheck['name']);
        $this->assertFalse($heartbeatCheck['ok']);
    }

    public function testReadinessRequiresAliveReadyAndBroker(): void
    {
        $state = new HealthState();
        $state->markReady();

        $connections = m::mock(ConnectionManagerInterface::class);
        $connections->shouldReceive('isConnected')->once()->andReturn(true);

        $check = new HealthCheck($state, null, $connections);
        $result = $check->readiness();

        $this->assertTrue($result['ok']);
        $this->assertSame('readiness', $result['kind']);
    }

    public function testReadinessFailsWhenBrokerDown(): void
    {
        $state = new HealthState();
        $state->markReady();

        $connections = m::mock(ConnectionManagerInterface::class);
        $connections->shouldReceive('isConnected')->once()->andReturn(false);

        $check = new HealthCheck($state, null, $connections);
        $result = $check->readiness();

        $this->assertFalse($result['ok']);
        $found = false;
        foreach ($result['checks'] as $sub) {
            if ($sub['name'] === 'broker_connection') {
                $found = true;
                $this->assertFalse($sub['ok']);
            }
        }
        $this->assertTrue($found, 'expected broker_connection check');
    }

    public function testReadinessFailsWhenWatchedQueueMissing(): void
    {
        $state = new HealthState();
        $state->markReady();

        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('getQueueStatistics')
            ->once()
            ->with('orders')
            ->andReturn([]);

        $check = (new HealthCheck($state, $amqp))->watchQueues(['orders']);
        $result = $check->readiness();

        $this->assertFalse($result['ok']);
    }

    public function testReadinessFailsWhenBacklogTooLarge(): void
    {
        $state = new HealthState();
        $state->markReady();

        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('getQueueStatistics')
            ->once()
            ->with('orders')
            ->andReturn(['messages' => 5000]);

        $check = (new HealthCheck($state, $amqp))
            ->watchQueues(['orders'])
            ->maxBacklog(1000);
        $result = $check->readiness();

        $this->assertFalse($result['ok']);
        $backlogCheck = end($result['checks']);
        $this->assertSame('queue:orders', $backlogCheck['name']);
        $this->assertStringContainsString('5000', $backlogCheck['message']);
    }

    public function testSnapshotCombinesBothProbes(): void
    {
        $state = new HealthState();
        $state->markReady();
        $check = new HealthCheck($state);

        $snapshot = $check->snapshot();

        $this->assertArrayHasKey('liveness', $snapshot);
        $this->assertArrayHasKey('readiness', $snapshot);
        $this->assertArrayHasKey('state', $snapshot);
        $this->assertTrue($snapshot['liveness']['ok']);
    }
}
