<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\TypedMessage;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Bschmitt\Amqp\Test\Support\Fixtures\OrderCreatedMessage;

/**
 * Unit tests for the {@see TypedMessage} convenience base class.
 *
 * Covers:
 *  - Reflection-driven default `toPayload()` / `fromPayload()`.
 *  - Routing key / exchange / schema defaults exposed by overrides.
 *  - Round-trip through the fixture {@see OrderCreatedMessage}.
 *
 * Subclassing-only ergonomics tests; for the integration with
 * {@see \Bschmitt\Amqp\Core\Amqp::publishTyped()} see {@see AmqpTypedMessagingTest}.
 */
class TypedMessageTest extends BaseTestCase
{
    public function testToPayloadReflectsPublicProperties(): void
    {
        $message = new OrderCreatedMessage('order-1', 49.99, 'USD');

        $this->assertSame([
            'orderId' => 'order-1',
            'total' => 49.99,
            'currency' => 'USD',
        ], $message->toPayload());
    }

    public function testFromPayloadReconstructsSubclass(): void
    {
        $reconstructed = OrderCreatedMessage::fromPayload([
            'orderId' => 'order-2',
            'total' => 12.5,
            'currency' => 'EUR',
        ]);

        $this->assertInstanceOf(OrderCreatedMessage::class, $reconstructed);
        $this->assertSame('order-2', $reconstructed->orderId);
        $this->assertSame(12.5, $reconstructed->total);
        $this->assertSame('EUR', $reconstructed->currency);
    }

    public function testFromPayloadIgnoresUnknownKeys(): void
    {
        $reconstructed = OrderCreatedMessage::fromPayload([
            'orderId' => 'order-3',
            'total' => 1,
            'currency' => 'GBP',
            'mystery' => 'should-be-ignored',
        ]);

        $this->assertInstanceOf(OrderCreatedMessage::class, $reconstructed);
        $this->assertSame('order-3', $reconstructed->orderId);
        $this->assertFalse(property_exists($reconstructed, 'mystery'));
    }

    public function testFromPayloadLeavesMissingFieldsAtTheirDefaults(): void
    {
        $reconstructed = OrderCreatedMessage::fromPayload(['orderId' => 'order-4']);

        $this->assertSame('order-4', $reconstructed->orderId);
        $this->assertNull($reconstructed->total);
        $this->assertNull($reconstructed->currency);
    }

    public function testRoundTripIsLossless(): void
    {
        $message = new OrderCreatedMessage('order-5', 75.25, 'USD');
        $reconstructed = OrderCreatedMessage::fromPayload($message->toPayload());

        $this->assertEquals($message, $reconstructed);
    }

    public function testStaticDefaultsAreOptional(): void
    {
        $message = new class extends TypedMessage {
            public $foo;
            public function __construct() { $this->foo = 'bar'; }
        };

        $class = get_class($message);
        $this->assertNull($class::schema());
        $this->assertNull($class::routingKey());
        $this->assertNull($class::exchange());
    }

    public function testFixtureExposesRoutingKeyExchangeAndSchema(): void
    {
        $this->assertSame('orders.created', OrderCreatedMessage::routingKey());
        $this->assertSame('shop.events', OrderCreatedMessage::exchange());

        $schema = OrderCreatedMessage::schema();
        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type']);
        $this->assertContains('orderId', $schema['required']);
    }
}
