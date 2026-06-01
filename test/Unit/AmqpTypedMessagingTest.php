<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Contracts\BatchManagerInterface;
use Bschmitt\Amqp\Contracts\ConsumerFactoryInterface;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Contracts\PublisherInterface;
use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Exception\SchemaValidationException;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Test\Support\Fixtures\OrderCreatedMessage;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

/**
 * Integration-shaped tests for the typed-messaging + delayed-publishing
 * helpers on {@see Amqp}.
 *
 * No broker involved — publisher/consumer factories are mocked. The tests
 * assert end-to-end behaviour: payload serialisation, contract defaults
 * (routing key + exchange + content-type), schema validation on both
 * directions, and the TTL queue topology produced by `publishLater()`.
 */
class AmqpTypedMessagingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPublishTypedSerializesPayloadAndPicksUpContractDefaults(): void
    {
        [$amqp, $publisherFactory] = $this->makeAmqp();

        $publisher = Mockery::mock(PublisherInterface::class);

        $captured = new \stdClass();
        $captured->routingKey = null;
        $captured->message = null;
        $captured->properties = null;

        $publisherFactory->shouldReceive('create')
            ->once()
            ->andReturnUsing(function ($properties) use ($publisher, $captured) {
                $captured->properties = $properties;
                return $publisher;
            });

        $publisher->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($routingKey, $message) use ($captured) {
                $captured->routingKey = $routingKey;
                $captured->message = $message;
                return true;
            });

        $result = $amqp->publishTyped(new OrderCreatedMessage('order-1', 19.99, 'USD'));
        $this->assertTrue($result);

        // routing key + exchange come from the contract.
        $this->assertSame('orders.created', $captured->routingKey);
        $this->assertSame('shop.events', $captured->properties['exchange']);
        $this->assertSame('application/json', $captured->properties['content_type']);

        $decoded = json_decode($captured->message->getBody(), true);
        $this->assertSame([
            'orderId' => 'order-1',
            'total' => 19.99,
            'currency' => 'USD',
        ], $decoded);
    }

    public function testPublishTypedHonoursCallerRoutingKeyOverride(): void
    {
        [$amqp, $publisherFactory] = $this->makeAmqp();
        $publisher = Mockery::mock(PublisherInterface::class);

        $publisherFactory->shouldReceive('create')->once()->andReturn($publisher);

        $captured = null;
        $publisher->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($routingKey, $message) use (&$captured) {
                $captured = $routingKey;
                return true;
            });

        $amqp->publishTyped(
            new OrderCreatedMessage('order-2', 9, 'USD'),
            ['routing' => 'orders.created.priority']
        );

        $this->assertSame('orders.created.priority', $captured);
    }

    public function testPublishTypedRejectsInvalidPayloadViaSchema(): void
    {
        [$amqp, $publisherFactory] = $this->makeAmqp();
        $publisherFactory->shouldNotReceive('create');

        $this->expectException(SchemaValidationException::class);

        // Missing required `currency`.
        $amqp->publishTyped(new OrderCreatedMessage('order-x', 10));
    }

    public function testConsumeTypedDeserializesAndPassesTypedInstanceToCallback(): void
    {
        [$amqp, $publisherFactory, $consumerFactory] = $this->makeAmqp();

        $consumer = Mockery::mock(ConsumerInterface::class);
        $consumer->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $closure) use ($consumer) {
                $this->assertSame('orders.queue', $queue);
                $body = json_encode([
                    'orderId' => 'order-3',
                    'total' => 7.5,
                    'currency' => 'EUR',
                ]);
                $closure(new AMQPMessage($body), $consumer);
                return true;
            });

        $consumerFactory->shouldReceive('create')->once()->andReturn($consumer);

        $captured = null;
        $amqp->consumeTyped('orders.queue', OrderCreatedMessage::class, function ($typed, $message, $resolver) use (&$captured) {
            $captured = $typed;
        });

        $this->assertInstanceOf(OrderCreatedMessage::class, $captured);
        $this->assertSame('order-3', $captured->orderId);
        $this->assertSame(7.5, $captured->total);
        $this->assertSame('EUR', $captured->currency);
    }

    public function testConsumeTypedThrowsOnSchemaValidationFailure(): void
    {
        [$amqp, $publisherFactory, $consumerFactory] = $this->makeAmqp();

        $consumer = Mockery::mock(ConsumerInterface::class);
        $consumer->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $closure) use ($consumer) {
                // Missing required fields.
                $closure(new AMQPMessage('{"orderId":"x"}'), $consumer);
                return true;
            });

        $consumerFactory->shouldReceive('create')->once()->andReturn($consumer);

        $caught = null;
        try {
            $amqp->consumeTyped('orders.queue', OrderCreatedMessage::class, function () {
                // shouldn't be reached
            });
        } catch (SchemaValidationException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertNotEmpty($caught->errors());
    }

    public function testConsumeTypedRejectsInvalidContractClass(): void
    {
        [$amqp] = $this->makeAmqp();

        $this->expectException(\InvalidArgumentException::class);
        $amqp->consumeTyped('q', \stdClass::class, function () {});
    }

    public function testPublishLaterUsesTtlQueueByDefault(): void
    {
        [$amqp, $publisherFactory] = $this->makeAmqp();
        $publisher = Mockery::mock(PublisherInterface::class);

        $captured = new \stdClass();
        $publisherFactory->shouldReceive('create')
            ->once()
            ->andReturnUsing(function ($properties) use ($publisher, $captured) {
                $captured->properties = $properties;
                return $publisher;
            });

        $publisher->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($routingKey, $message) use ($captured) {
                $captured->routingKey = $routingKey;
                return true;
            });

        $amqp->publishLater('orders.created', '{"id":1}', 3000, [
            'exchange' => 'shop.events',
        ]);

        $this->assertSame('orders.created.delayed.3000', $captured->routingKey);
        $qp = $captured->properties['queue_properties'];
        $this->assertSame(3000, $qp['x-message-ttl']);
        $this->assertSame('shop.events', $qp['x-dead-letter-exchange']);
        $this->assertSame('orders.created', $qp['x-dead-letter-routing-key']);
    }

    public function testPublishLaterPluginStrategyUsesXDelayHeader(): void
    {
        [$amqp, $publisherFactory] = $this->makeAmqp();
        $publisher = Mockery::mock(PublisherInterface::class);

        $captured = new \stdClass();
        $publisherFactory->shouldReceive('create')
            ->once()
            ->andReturnUsing(function ($properties) use ($publisher, $captured) {
                $captured->properties = $properties;
                return $publisher;
            });

        $publisher->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($routingKey, $message) use ($captured) {
                $captured->routingKey = $routingKey;
                return true;
            });

        $amqp->publishLater('orders.created', 'body', 4500, [
            'exchange' => 'shop.delayed',
            'delay_strategy' => 'plugin',
        ]);

        $this->assertSame('orders.created', $captured->routingKey);
        $this->assertSame(4500, $captured->properties['application_headers']['x-delay']);
    }

    public function testPublishTypedLaterCombinesSchemaValidationAndDelayQueue(): void
    {
        [$amqp, $publisherFactory] = $this->makeAmqp();
        $publisher = Mockery::mock(PublisherInterface::class);

        $captured = new \stdClass();
        $publisherFactory->shouldReceive('create')
            ->once()
            ->andReturnUsing(function ($properties) use ($publisher, $captured) {
                $captured->properties = $properties;
                return $publisher;
            });

        $publisher->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($routingKey, $message) use ($captured) {
                $captured->routingKey = $routingKey;
                return true;
            });

        $amqp->publishTypedLater(new OrderCreatedMessage('order-z', 1.0, 'USD'), 6000);

        $this->assertSame('orders.created.delayed.6000', $captured->routingKey);
        $this->assertSame('shop.events', $captured->properties['exchange']);
        $this->assertSame(6000, $captured->properties['queue_properties']['x-message-ttl']);
    }

    public function testPublishTypedLaterRejectsInvalidPayload(): void
    {
        [$amqp, $publisherFactory] = $this->makeAmqp();
        $publisherFactory->shouldNotReceive('create');

        $this->expectException(SchemaValidationException::class);
        $amqp->publishTypedLater(new OrderCreatedMessage(null, 1.0, 'USD'), 1000);
    }

    /**
     * @return array{0: Amqp, 1: \Mockery\MockInterface, 2: \Mockery\MockInterface, 3: \Mockery\MockInterface}
     */
    private function makeAmqp(): array
    {
        $publisherFactory = Mockery::mock(PublisherFactoryInterface::class);
        $consumerFactory = Mockery::mock(ConsumerFactoryInterface::class);
        $batchManager = Mockery::mock(BatchManagerInterface::class);
        $messageFactory = new MessageFactory();

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        return [$amqp, $publisherFactory, $consumerFactory, $batchManager];
    }
}
