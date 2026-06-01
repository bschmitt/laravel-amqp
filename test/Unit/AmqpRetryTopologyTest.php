<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Contracts\BatchManagerInterface;
use Bschmitt\Amqp\Contracts\ConsumerFactoryInterface;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Contracts\PublisherInterface;
use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Support\DeadLetterTopology;
use Bschmitt\Amqp\Support\RetryHandler;
use Bschmitt\Amqp\Support\RetryPolicy;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integration-shaped (still no broker) tests for the retry/DLQ helpers added
 * to {@see Amqp}.
 *
 * The PublisherFactory contract is mocked so we can:
 *   - assert declareRetryTopology() declares work + DLQ + one retry queue per
 *     planned delay (exactly that many publisher creates, no publishes);
 *   - assert consumeWithRetry() wires the consumer through a RetryHandler that
 *     republishes on failure and acks the original.
 */
class AmqpRetryTopologyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testDeclareRetryTopologyCreatesWorkDlqAndOneRetryQueuePerDelay(): void
    {
        $publisherFactory = Mockery::mock(PublisherFactoryInterface::class);
        $consumerFactory = Mockery::mock(ConsumerFactoryInterface::class);
        $batchManager = Mockery::mock(BatchManagerInterface::class);
        $messageFactory = new MessageFactory();

        $createdQueues = [];
        $publisherFactory->shouldReceive('create')
            ->times(3) // work + DLQ + 1 unique retry delay
            ->andReturnUsing(function (array $properties) use (&$createdQueues) {
                $createdQueues[] = $properties['queue'];
                $publisher = Mockery::mock(PublisherInterface::class);
                $publisher->shouldNotReceive('publish');
                return $publisher;
            });

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(3, 2500))->on('amq.topic');
        $amqp->declareRetryTopology($topology);

        $this->assertSame(['jobs', 'jobs.dlq', 'jobs.retry.2500'], $createdQueues);
    }

    public function testDeclareRetryTopologyEnumeratesAllUniqueExponentialDelays(): void
    {
        $publisherFactory = Mockery::mock(PublisherFactoryInterface::class);
        $consumerFactory = Mockery::mock(ConsumerFactoryInterface::class);
        $batchManager = Mockery::mock(BatchManagerInterface::class);
        $messageFactory = new MessageFactory();

        $createdQueues = [];
        $publisherFactory->shouldReceive('create')
            ->andReturnUsing(function (array $properties) use (&$createdQueues) {
                $createdQueues[] = $properties['queue'];
                return Mockery::mock(PublisherInterface::class);
            });

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::exponential(3, 100, 2.0))->on('amq.topic');
        $amqp->declareRetryTopology($topology);

        $this->assertSame([
            'jobs',
            'jobs.dlq',
            'jobs.retry.100',
            'jobs.retry.200',
            'jobs.retry.400',
        ], $createdQueues);
    }

    public function testTopologyHelperReturnsBoundDeadLetterTopology(): void
    {
        $publisherFactory = Mockery::mock(PublisherFactoryInterface::class);
        $consumerFactory = Mockery::mock(ConsumerFactoryInterface::class);
        $batchManager = Mockery::mock(BatchManagerInterface::class);
        $messageFactory = new MessageFactory();

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $topology = $amqp->topology('jobs', RetryPolicy::fixed(2, 100));
        $this->assertInstanceOf(DeadLetterTopology::class, $topology);
        $this->assertSame('jobs', $topology->getQueue());
        $this->assertSame(2, $topology->getRetryPolicy()->maxAttempts());
    }

    public function testRetryHandlerHelperUsesInjectedFactories(): void
    {
        $publisherFactory = Mockery::mock(PublisherFactoryInterface::class);
        $consumerFactory = Mockery::mock(ConsumerFactoryInterface::class);
        $batchManager = Mockery::mock(BatchManagerInterface::class);
        $messageFactory = new MessageFactory();

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(1, 100));
        $wrapper = $amqp->retryHandler(function () {}, $topology);
        $this->assertInstanceOf(RetryHandler::class, $wrapper);
        $this->assertSame($topology, $wrapper->getTopology());
    }

    public function testConsumeWithRetryWrapsHandlerThroughRetryPipeline(): void
    {
        $publisherFactory = Mockery::mock(PublisherFactoryInterface::class);
        $consumerFactory = Mockery::mock(ConsumerFactoryInterface::class);
        $batchManager = Mockery::mock(BatchManagerInterface::class);
        $messageFactory = new MessageFactory();

        // The consumer's consume() captures the wrapper closure and immediately
        // invokes it with a synthetic message to prove that failures get
        // routed through RetryHandler => publisher.publish() => resolver.ack().
        $consumer = Mockery::mock(ConsumerInterface::class);
        $consumer->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queueName, $closure) use ($consumer) {
                $this->assertSame('jobs', $queueName);
                $closure(new AMQPMessage('payload'), $consumer);
                return true;
            });
        $consumer->shouldReceive('acknowledge')->once();

        $consumerFactory->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $properties) use ($consumer) {
                $this->assertSame('jobs', $properties['queue']);
                $this->assertArrayHasKey('queue_properties', $properties);
                $this->assertSame('jobs.dlq', $properties['queue_properties']['x-dead-letter-routing-key']);
                return $consumer;
            });

        $publisher = Mockery::mock(PublisherInterface::class);
        $publisher->shouldReceive('publish')->once()->andReturn(true);
        $publisherFactory->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $properties) use ($publisher) {
                $this->assertStringStartsWith('jobs.retry.', $properties['queue']);
                $this->assertSame(750, $properties['queue_properties']['x-message-ttl']);
                return $publisher;
            });

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);
        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(2, 750))->on('amq.topic');

        $invocations = 0;
        $result = $amqp->consumeWithRetry($topology, function () use (&$invocations) {
            $invocations++;
            throw new RuntimeException('boom');
        });

        $this->assertTrue($result);
        $this->assertSame(1, $invocations);
    }
}
