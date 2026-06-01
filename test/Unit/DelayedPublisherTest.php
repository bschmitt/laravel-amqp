<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Contracts\PublisherInterface;
use Bschmitt\Amqp\Models\Message as AmqpModelMessage;
use Bschmitt\Amqp\Support\DelayedPublisher;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use InvalidArgumentException;
use Mockery;

/**
 * Unit tests for {@see DelayedPublisher}.
 *
 * The publisher factory is mocked, so each test captures the property bag
 * passed to `create()` and the call made to `publish()`. We assert:
 *  - TTL strategy declares the per-delay queue with the right DLX wiring.
 *  - Plugin strategy publishes straight to the target exchange with the
 *    x-delay header.
 *  - Zero delay is short-circuited to a direct publish.
 *  - Invalid arguments are rejected up-front.
 */
class DelayedPublisherTest extends BaseTestCase
{
    public function testTtlStrategyPublishesToPerDelayQueueWithDlxBackToTarget(): void
    {
        $captured = $this->capturePublish();
        $publisher = new DelayedPublisher($captured['factory']);

        $publisher->publishLater('orders.created', '{"id":1}', 5000, [
            'exchange' => 'shop.events',
            'exchange_type' => 'topic',
        ]);

        $this->assertSame('orders.created.delayed.5000', $captured['ref']->routingKey);
        $this->assertSame('orders.created.delayed.5000', $captured['ref']->properties['queue']);
        $this->assertSame(['orders.created.delayed.5000'], $captured['ref']->properties['routing']);
        $this->assertTrue($captured['ref']->properties['queue_force_declare']);

        $qp = $captured['ref']->properties['queue_properties'];
        $this->assertSame('shop.events', $qp['x-dead-letter-exchange']);
        $this->assertSame('orders.created', $qp['x-dead-letter-routing-key']);
        $this->assertSame(5000, $qp['x-message-ttl']);
        $this->assertGreaterThanOrEqual(10000, $qp['x-expires']);
    }

    public function testTtlStrategyPreservesCustomQueueProperties(): void
    {
        $captured = $this->capturePublish();
        $publisher = new DelayedPublisher($captured['factory']);

        $publisher->publishLater('orders.created', 'body', 1000, [
            'exchange' => 'shop.events',
            'queue_properties' => ['x-max-length' => 10000],
        ]);

        $qp = $captured['ref']->properties['queue_properties'];
        $this->assertSame(10000, $qp['x-max-length']);
        $this->assertSame(1000, $qp['x-message-ttl']);
    }

    public function testPluginStrategyPublishesToTargetExchangeWithXDelayHeader(): void
    {
        $captured = $this->capturePublish();
        $publisher = new DelayedPublisher($captured['factory']);

        $publisher->publishLater('orders.created', 'body', 7500, [
            'exchange' => 'shop.delayed',
        ], DelayedPublisher::STRATEGY_PLUGIN);

        $this->assertSame('orders.created', $captured['ref']->routingKey);
        $this->assertArrayHasKey('application_headers', $captured['ref']->properties);
        $this->assertSame(7500, $captured['ref']->properties['application_headers']['x-delay']);

        // Plugin strategy does NOT create a separate delay queue, so the
        // queue_properties must not carry TTL/DLX keys.
        $qp = $captured['ref']->properties['queue_properties'] ?? [];
        $this->assertArrayNotHasKey('x-message-ttl', $qp);
        $this->assertArrayNotHasKey('x-dead-letter-exchange', $qp);
    }

    public function testZeroDelayShortCircuitsToDirectPublish(): void
    {
        $captured = $this->capturePublish();
        $publisher = new DelayedPublisher($captured['factory']);

        $publisher->publishLater('orders.created', 'body', 0, [
            'exchange' => 'shop.events',
        ]);

        $this->assertSame('orders.created', $captured['ref']->routingKey);
        $this->assertSame('orders.created', $captured['ref']->properties['routing']);
        // Direct publish doesn't add x-message-ttl since there is no delay queue.
        $this->assertArrayNotHasKey('queue_properties', array_filter(
            $captured['ref']->properties,
            function ($v, $k) { return $k === 'x-message-ttl'; },
            ARRAY_FILTER_USE_BOTH
        ));
    }

    public function testNegativeDelayIsRejected(): void
    {
        $publisher = new DelayedPublisher(Mockery::mock(PublisherFactoryInterface::class));

        $this->expectException(InvalidArgumentException::class);
        $publisher->publishLater('x', 'body', -1);
    }

    public function testUnsupportedStrategyIsRejected(): void
    {
        $publisher = new DelayedPublisher(Mockery::mock(PublisherFactoryInterface::class));

        $this->expectException(InvalidArgumentException::class);
        $publisher->publishLater('x', 'body', 100, [], 'magic');
    }

    public function testDelayQueueNameFollowsConvention(): void
    {
        $this->assertSame('orders.delayed.250', DelayedPublisher::delayQueueName('orders', 250));
        $this->assertSame('_default.delayed.1000', DelayedPublisher::delayQueueName('', 1000));
    }

    public function testAcceptsPreBuiltMessageInstance(): void
    {
        $captured = $this->capturePublish();
        $publisher = new DelayedPublisher($captured['factory']);

        $message = new AmqpModelMessage('precooked-body', ['content_type' => 'text/plain']);
        $publisher->publishLater('orders.created', $message, 2000, [
            'exchange' => 'shop.events',
        ]);

        $this->assertSame($message, $captured['ref']->message);
    }

    /**
     * @return array{factory: PublisherFactoryInterface, ref: \stdClass}
     */
    private function capturePublish(): array
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $publisher = Mockery::mock(PublisherInterface::class);

        $ref = new \stdClass();
        $ref->routingKey = null;
        $ref->message = null;
        $ref->properties = null;

        $factory->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $properties) use ($ref, $publisher) {
                $ref->properties = $properties;
                return $publisher;
            });

        $publisher->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($routingKey, $message) use ($ref) {
                $ref->routingKey = $routingKey;
                $ref->message = $message;
                return true;
            });

        return ['factory' => $factory, 'ref' => $ref];
    }
}
