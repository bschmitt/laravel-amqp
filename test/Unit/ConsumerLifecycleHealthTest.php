<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\ConsumerLifecycle;
use Bschmitt\Amqp\Support\HealthState;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

class ConsumerLifecycleHealthTest extends TestCase
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

    public function testStartingMarksHealthReady(): void
    {
        $state = new HealthState();
        $lifecycle = (new ConsumerLifecycle())->withHealth($state);
        $this->assertFalse($state->isReady());

        $lifecycle->fireStarting();

        $this->assertTrue($state->isReady());
        $this->assertTrue($state->isAlive());
    }

    public function testStoppingMarksHealthDead(): void
    {
        $state = new HealthState();
        $lifecycle = (new ConsumerLifecycle())->withHealth($state);
        $lifecycle->fireStarting();

        $lifecycle->fireStopping();

        $this->assertFalse($state->isAlive());
        $this->assertFalse($state->isReady());
    }

    public function testMessageHookStampsHeartbeatAndProcessedCount(): void
    {
        $state = new HealthState();
        $lifecycle = (new ConsumerLifecycle())->withHealth($state);

        $lifecycle->fireMessage(new AMQPMessage('x'));
        $lifecycle->fireMessage(new AMQPMessage('y'));

        $this->assertSame(2, $state->messagesProcessed());
        $this->assertLessThan(1.0, $state->ageSinceHeartbeat());
    }

    public function testErrorHookIncrementsErrorCounter(): void
    {
        $state = new HealthState();
        $lifecycle = (new ConsumerLifecycle())->withHealth($state);

        $lifecycle->fireError(new \RuntimeException('boom'));

        $this->assertSame(1, $state->errors());
    }

    public function testRequestStopMarksNotReady(): void
    {
        $state = new HealthState();
        $lifecycle = (new ConsumerLifecycle())->withHealth($state);
        $lifecycle->fireStarting();
        $this->assertTrue($state->isReady());

        $lifecycle->requestStop();

        $this->assertFalse($state->isReady());
        $this->assertTrue($state->isAlive());
    }
}
