<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Queue\AmqpJob;
use Bschmitt\Amqp\Test\Support\LaravelQueueTestCase;

/**
 * End-to-end coverage of the native Laravel queue driver against a real
 * RabbitMQ broker. Each test owns a unique queue that is removed in tearDown.
 */
class LaravelQueueIntegrationTest extends LaravelQueueTestCase
{
    public function testPushRawAndPopRoundTripPreservesPayload(): void
    {
        $queueName = $this->uniqueQueueName();
        $queue = $this->makeQueue($queueName);

        $payload = $this->laravelJobPayload(['id' => 'job-uuid-1']);
        $returnedId = $queue->pushRaw($payload, $queueName);

        $this->assertSame('job-uuid-1', $returnedId);

        /** @var AmqpJob|null $job */
        $job = $queue->pop($queueName);

        $this->assertInstanceOf(AmqpJob::class, $job);
        $this->assertSame($payload, $job->getRawBody());
        $this->assertSame('job-uuid-1', $job->getJobId());
        $this->assertSame(1, $job->attempts(), 'first execution should report attempt = 1');

        $job->delete();
    }

    public function testSizeReflectsPendingMessageCount(): void
    {
        $queueName = $this->uniqueQueueName();
        $queue = $this->makeQueue($queueName);

        $this->assertSame(0, $queue->size($queueName));

        $queue->pushRaw($this->laravelJobPayload(), $queueName);
        $queue->pushRaw($this->laravelJobPayload(), $queueName);

        // Allow the broker a moment to register the publishes before counting.
        usleep(150000);

        $this->assertSame(2, $queue->size($queueName));
    }

    public function testPopReturnsNullWhenQueueIsEmpty(): void
    {
        $queueName = $this->uniqueQueueName();
        $queue = $this->makeQueue($queueName);

        $this->assertNull($queue->pop($queueName));
    }

    public function testDeleteAcksMessageAndDecrementsQueueSize(): void
    {
        $queueName = $this->uniqueQueueName();
        $queue = $this->makeQueue($queueName);

        $queue->pushRaw($this->laravelJobPayload(), $queueName);
        usleep(100000);

        /** @var AmqpJob $job */
        $job = $queue->pop($queueName);
        $this->assertInstanceOf(AmqpJob::class, $job);

        $job->delete();

        // After ack the message must not come back, even after a re-pop.
        $this->assertNull($queue->pop($queueName));
        $this->assertSame(0, $queue->size($queueName));
    }

    public function testReleaseWithoutDelayRequeuesMessageForRedelivery(): void
    {
        $queueName = $this->uniqueQueueName();
        $queue = $this->makeQueue($queueName);

        $payload = $this->laravelJobPayload(['id' => 'requeue-me']);
        $queue->pushRaw($payload, $queueName);
        usleep(100000);

        /** @var AmqpJob $first */
        $first = $queue->pop($queueName);
        $this->assertInstanceOf(AmqpJob::class, $first);
        $first->release(0);

        usleep(150000);

        /** @var AmqpJob $second */
        $second = $queue->pop($queueName);
        $this->assertInstanceOf(AmqpJob::class, $second);
        $this->assertSame($payload, $second->getRawBody());

        $second->delete();
    }
}
