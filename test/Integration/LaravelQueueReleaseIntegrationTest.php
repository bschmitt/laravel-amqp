<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Queue\AmqpJob;
use Bschmitt\Amqp\Test\Support\LaravelQueueTestCase;

/**
 * Verifies that {@see AmqpJob::release()} correctly re-queues a job, with and
 * without delay, against a real broker.
 */
class LaravelQueueReleaseIntegrationTest extends LaravelQueueTestCase
{
    public function testReleaseWithoutDelayMakesJobImmediatelyRedeliverable(): void
    {
        $queueName = $this->uniqueQueueName('laravel-queue-release');
        $queue = $this->makeQueue($queueName);

        $payload = $this->laravelJobPayload(['id' => 'release-no-delay']);
        $queue->pushRaw($payload, $queueName);
        usleep(100000);

        /** @var AmqpJob $first */
        $first = $queue->pop($queueName);
        $this->assertInstanceOf(AmqpJob::class, $first);
        $this->assertSame(1, $first->attempts());

        $first->release(0);
        usleep(150000);

        /** @var AmqpJob $second */
        $second = $queue->pop($queueName);
        $this->assertInstanceOf(AmqpJob::class, $second);
        $this->assertSame($payload, $second->getRawBody());

        $second->delete();
        $this->assertSame(0, $queue->size($queueName));
    }

    public function testReleaseWithDelayHoldsJobInDelayQueueUntilTtlExpires(): void
    {
        $queueName = $this->uniqueQueueName('laravel-queue-release-delay');
        $queue = $this->makeQueue($queueName);

        // Declare the main queue before any DLX traffic arrives.
        $this->assertSame(0, $queue->size($queueName));

        $payload = $this->laravelJobPayload(['id' => 'release-delayed']);
        $queue->pushRaw($payload, $queueName);
        usleep(100000);

        /** @var AmqpJob $first */
        $first = $queue->pop($queueName);
        $this->assertInstanceOf(AmqpJob::class, $first);

        $delaySeconds = 2;
        $first->release($delaySeconds);
        $this->trackQueue(sprintf('%s.delay.%d', $queueName, $delaySeconds * 1000));

        // Original delivery is acked; nothing immediately visible.
        $this->assertNull($queue->pop($queueName));

        sleep($delaySeconds + 1);

        /** @var AmqpJob $second */
        $second = $queue->pop($queueName);
        $this->assertInstanceOf(AmqpJob::class, $second);
        $this->assertSame($payload, $second->getRawBody());

        $second->delete();
    }
}
