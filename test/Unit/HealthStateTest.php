<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\HealthState;
use PHPUnit\Framework\TestCase;

class HealthStateTest extends TestCase
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

    public function testStartsAliveButNotReady(): void
    {
        $state = new HealthState();

        $this->assertTrue($state->isAlive());
        $this->assertFalse($state->isReady());
        $this->assertSame(0, $state->messagesProcessed());
    }

    public function testMarkReadyFlipsReadyFlagAndStampsReason(): void
    {
        $state = new HealthState();
        $state->markReady('consumer up');

        $this->assertTrue($state->isReady());
        $this->assertSame('consumer up', $state->reason());
    }

    public function testMarkDeadFlipsLivenessAndReadiness(): void
    {
        $state = new HealthState();
        $state->markReady();
        $state->markDead('crashed');

        $this->assertFalse($state->isAlive());
        $this->assertFalse($state->isReady());
        $this->assertSame('crashed', $state->reason());
    }

    public function testHeartbeatResetsAge(): void
    {
        $state = new HealthState();
        usleep(2000);
        $before = $state->ageSinceHeartbeat();
        $state->heartbeat();
        $after = $state->ageSinceHeartbeat();

        $this->assertGreaterThan(0.0, $before);
        $this->assertLessThanOrEqual($before, $after);
    }

    public function testRecordProcessedAndErrorsAccumulate(): void
    {
        $state = new HealthState();
        $state->recordProcessed();
        $state->recordProcessed();
        $state->recordError();

        $this->assertSame(2, $state->messagesProcessed());
        $this->assertSame(1, $state->errors());
    }

    public function testInstanceIsSingleton(): void
    {
        $a = HealthState::instance();
        $b = HealthState::instance();

        $this->assertSame($a, $b);
    }

    public function testPersistAndHydrateRoundTrip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'amqp_health_');
        try {
            $state = new HealthState($path);
            $state->markReady('test');
            $state->recordProcessed();
            $state->recordProcessed();

            $copy = new HealthState($path);
            $this->assertFalse($copy->isReady()); // not yet hydrated
            $copy->hydrateFromDisk();

            $this->assertTrue($copy->isReady());
            $this->assertSame('test', $copy->reason());
            $this->assertSame(2, $copy->messagesProcessed());
        } finally {
            @unlink($path);
        }
    }
}
