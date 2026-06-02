<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Contracts\ShouldPublishToAmqpInterface;
use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Events\AmqpEventListener;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Mockery as m;

class TestOrderCreatedEvent implements ShouldPublishToAmqpInterface
{
    /** @var string */
    public $orderId;

    public function __construct(string $orderId)
    {
        $this->orderId = $orderId;
    }
}

class TestOverriddenEvent implements ShouldPublishToAmqpInterface
{
    public function amqpRouting(): string
    {
        return 'custom.routing.key';
    }

    public function amqpPayload(): array
    {
        return ['custom' => true];
    }

    public function amqpExchange(): string
    {
        return 'app.events';
    }
}

class TestPlainEvent
{
    public $foo = 'bar';
}

class AmqpEventListenerTest extends BaseTestCase
{
    public function testListenerSkipsEventsWithoutMarker(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldNotReceive('publish');

        $listener = new AmqpEventListener($amqp);
        $listener->dispatch(TestPlainEvent::class, [new TestPlainEvent()]);

        $this->assertTrue(true); // strict-mode safeguard
    }

    public function testListenerPublishesMarkerEvents(): void
    {
        $capturedRouting = null;
        $capturedBody = null;
        $capturedProperties = null;

        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($routing, $body, $properties) use (&$capturedRouting, &$capturedBody, &$capturedProperties) {
                $capturedRouting = $routing;
                $capturedBody = $body;
                $capturedProperties = $properties;
                return true;
            });

        $listener = new AmqpEventListener($amqp);
        $listener->dispatch(TestOrderCreatedEvent::class, [new TestOrderCreatedEvent('o-1')]);

        $this->assertSame('test_order_created_event', $capturedRouting);
        $this->assertSame(['orderId' => 'o-1'], json_decode($capturedBody, true));
        $this->assertSame('application/json', $capturedProperties['content_type']);
    }

    public function testListenerHonoursOverrideMethods(): void
    {
        $captured = [];

        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($routing, $body, $properties) use (&$captured) {
                $captured = compact('routing', 'body', 'properties');
                return true;
            });

        $listener = new AmqpEventListener($amqp);
        $listener->dispatch(TestOverriddenEvent::class, [new TestOverriddenEvent()]);

        $this->assertSame('custom.routing.key', $captured['routing']);
        $this->assertSame(['custom' => true], json_decode($captured['body'], true));
        $this->assertSame('app.events', $captured['properties']['exchange']);
    }
}
