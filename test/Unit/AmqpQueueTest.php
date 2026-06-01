<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Contracts\PublisherInterface;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Models\Message;
use Bschmitt\Amqp\Queue\AmqpQueue;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Mockery;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Unit tests for {@see AmqpQueue}.
 *
 * The connection/channel layer is mocked via {@see PublisherFactoryInterface} so
 * these tests run without a broker and focus purely on behaviour: payload
 * shape, routing, delay-queue topology, and message properties.
 */
class AmqpQueueTest extends BaseTestCase
{
    /** @var array */
    private $amqpConfig;

    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->amqpConfig = include dirname(__DIR__, 2).'/config/amqp.php';
        $this->container = new Container();
        $this->container->instance('config', new Repository(['amqp' => $this->amqpConfig]));
    }

    public function testPushRawPublishesPayloadToConfiguredQueue(): void
    {
        $captured = $this->capturePublish('test-queue', ['test-queue']);

        $queue = $this->makeQueue($captured['factory'], ['queue' => 'test-queue']);
        $payload = $this->payload(['id' => 'abc-123']);

        $id = $queue->pushRaw($payload, 'test-queue');

        $this->assertSame('abc-123', $id);
        $this->assertInstanceOf(Message::class, $captured['ref']->message);
        $this->assertSame($payload, $captured['ref']->message->getBody());
    }

    public function testPushedMessageUsesPersistentDeliveryMode(): void
    {
        $captured = $this->capturePublish('jobs', ['jobs']);

        $queue = $this->makeQueue($captured['factory'], ['queue' => 'jobs']);
        $queue->pushRaw($this->payload(), 'jobs');

        $properties = $captured['ref']->message->get_properties();
        $this->assertSame('application/json', $properties['content_type']);
        $this->assertSame(2, $properties['delivery_mode'], 'queued jobs must be persistent');
    }

    public function testPushedMessageHasZeroAttemptsHeaderByDefault(): void
    {
        $captured = $this->capturePublish('jobs', ['jobs']);

        $queue = $this->makeQueue($captured['factory'], ['queue' => 'jobs']);
        $queue->pushRaw($this->payload(), 'jobs');

        /** @var AMQPTable $headers */
        $headers = $captured['ref']->message->get('application_headers');
        $this->assertInstanceOf(AMQPTable::class, $headers);
        $this->assertSame(0, $headers->getNativeData()['laravel']['attempts']);
    }

    public function testPushRawPropagatesAttemptsOptionIntoHeaders(): void
    {
        $captured = $this->capturePublish('jobs', ['jobs']);

        $queue = $this->makeQueue($captured['factory'], ['queue' => 'jobs']);
        $queue->pushRaw($this->payload(), 'jobs', ['attempts' => 3]);

        /** @var AMQPTable $headers */
        $headers = $captured['ref']->message->get('application_headers');
        $this->assertSame(3, $headers->getNativeData()['laravel']['attempts']);
    }

    public function testPushSerialisesObjectJobAndAttachesUuid(): void
    {
        $captured = $this->capturePublish('jobs', ['jobs']);

        $queue = $this->makeQueue($captured['factory'], ['queue' => 'jobs']);
        $queue->push(new \stdClass(), '', 'jobs');

        $decoded = json_decode($captured['ref']->message->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('id', $decoded);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $decoded['id']);
        $this->assertSame(\Illuminate\Queue\CallQueuedHandler::class.'@call', $decoded['job']);
    }

    public function testLaterPublishesToDelayQueueWithDeadLetterProperties(): void
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $publisher = Mockery::mock(PublisherInterface::class);

        $capturedProperties = null;
        $factory->shouldReceive('create')
            ->once()
            ->withArgs(function (array $properties) use (&$capturedProperties) {
                $capturedProperties = $properties;

                return true;
            })
            ->andReturn($publisher);

        $publisher->shouldReceive('publish')
            ->once()
            ->withArgs(function (string $routingKey, Message $message) {
                $this->assertSame('jobs.delay.5000', $routingKey);

                return true;
            })
            ->andReturn(true);

        $queue = $this->makeQueue($factory, ['queue' => 'jobs']);

        $id = $queue->laterRaw(5, $this->payload(['id' => 'delayed-1']), 'jobs');

        $this->assertSame('delayed-1', $id);
        $this->assertSame('jobs.delay.5000', $capturedProperties['queue']);
        $this->assertSame(['jobs.delay.5000'], $capturedProperties['routing']);

        $qp = $capturedProperties['queue_properties'];
        $this->assertSame($this->amqpConfig['properties'][$this->amqpConfig['use']]['exchange'], $qp['x-dead-letter-exchange']);
        $this->assertSame('jobs', $qp['x-dead-letter-routing-key']);
        $this->assertSame(5000, $qp['x-message-ttl']);
        $this->assertSame(10000, $qp['x-expires']);
    }

    public function testLaterRawWithZeroDelayFallsBackToImmediateQueue(): void
    {
        $captured = $this->capturePublish('jobs', ['jobs']);

        $queue = $this->makeQueue($captured['factory'], ['queue' => 'jobs']);
        $id = $queue->laterRaw(0, $this->payload(['id' => 'now']), 'jobs');

        $this->assertSame('now', $id);
        $this->assertSame('jobs', $captured['ref']->routingKey);
    }

    public function testGetQueueFallsBackToConfiguredQueueThenDefault(): void
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $factory->shouldNotReceive('create');

        $queue = $this->makeQueue($factory, ['queue' => 'configured']);

        $this->assertSame('configured', $queue->getQueue(null));
        $this->assertSame('explicit', $queue->getQueue('explicit'));
    }

    public function testGetQueueFallsBackToDefaultWhenNothingConfigured(): void
    {
        // Strip the `queue` key from the upstream amqp.properties so we hit the
        // final `'default'` fallback in AmqpQueue::getQueue().
        $this->amqpConfig['properties'][$this->amqpConfig['use']]['queue'] = null;
        $this->container->instance('config', new Repository(['amqp' => $this->amqpConfig]));

        $factory = Mockery::mock(PublisherFactoryInterface::class);

        $queue = new AmqpQueue($this->container, ['driver' => 'amqp', 'connection' => $this->amqpConfig['use']], $factory, new MessageFactory());

        $this->assertSame('default', $queue->getQueue(null));
    }

    public function testRoutingKeyHonoursAmqpPropertiesRoutingOverride(): void
    {
        $this->amqpConfig['properties'][$this->amqpConfig['use']]['routing'] = ['custom.routing.key'];
        $this->container->instance('config', new Repository(['amqp' => $this->amqpConfig]));

        $captured = $this->capturePublish('custom.routing.key', ['custom.routing.key']);

        $queue = new AmqpQueue($this->container, ['driver' => 'amqp', 'connection' => $this->amqpConfig['use'], 'queue' => 'jobs', 'routing' => ['custom.routing.key']], $captured['factory'], new MessageFactory());

        $queue->pushRaw($this->payload(), 'jobs');

        $this->assertSame('custom.routing.key', $captured['ref']->routingKey);
    }

    /**
     * Build an AmqpQueue with the given publisher factory + queue config.
     */
    private function makeQueue(PublisherFactoryInterface $factory, array $extra = []): AmqpQueue
    {
        return new AmqpQueue(
            $this->container,
            array_merge([
                'driver' => 'amqp',
                'connection' => $this->amqpConfig['use'],
            ], $extra),
            $factory,
            new MessageFactory()
        );
    }

    /**
     * Sets up a factory + publisher that capture exactly one publish() call.
     *
     * @return array{factory: PublisherFactoryInterface, ref: object}
     */
    private function capturePublish(string $expectedRoutingKey, array $expectedRouting): array
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $publisher = Mockery::mock(PublisherInterface::class);

        $ref = new \stdClass();
        $ref->routingKey = null;
        $ref->message = null;
        $ref->properties = null;

        $factory->shouldReceive('create')
            ->once()
            ->withArgs(function (array $properties) use ($ref, $expectedRouting) {
                $ref->properties = $properties;
                $this->assertSame($expectedRouting, $properties['routing']);
                $this->assertTrue($properties['queue_force_declare']);

                return true;
            })
            ->andReturn($publisher);

        $publisher->shouldReceive('publish')
            ->once()
            ->withArgs(function (string $routingKey, Message $message) use ($ref, $expectedRoutingKey) {
                $ref->routingKey = $routingKey;
                $ref->message = $message;
                $this->assertSame($expectedRoutingKey, $routingKey);

                return true;
            })
            ->andReturn(true);

        return ['factory' => $factory, 'ref' => $ref];
    }

    private function payload(array $overrides = []): string
    {
        return json_encode(array_replace([
            'id' => '00000000-0000-0000-0000-000000000099',
            'uuid' => '00000000-0000-0000-0000-000000000099',
            'displayName' => 'App\\Jobs\\ExampleJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => 'App\\Jobs\\ExampleJob'],
        ], $overrides));
    }
}
