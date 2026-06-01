<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Queue\AmqpJob;
use Bschmitt\Amqp\Test\Support\LaravelQueueTestCase;

/**
 * Verifies the delay queue + dead-letter-exchange topology used by
 * AmqpQueue::laterRaw(): jobs published with a future-delivery delay land in
 * a per-TTL holding queue, expire, and are routed back to the main queue.
 *
 * These tests deliberately sleep for short, deterministic intervals; they are
 * slow by design but produce the strongest possible assertion of correctness.
 */
class LaravelQueueDelayIntegrationTest extends LaravelQueueTestCase
{
    public function testLaterRawDeliversMessageAfterDelayViaDeadLetterExchange(): void
    {
        $queueName = $this->uniqueQueueName('laravel-queue-delay');
        $queue = $this->makeQueue($queueName);

        // size() forces declaration of the main queue *before* the delay queue
        // expires - otherwise the DLX has no destination to deliver to.
        $this->assertSame(0, $queue->size($queueName));

        $payload = $this->laravelJobPayload(['id' => 'delayed-job']);
        $delaySeconds = 2;

        $returnedId = $queue->laterRaw($delaySeconds, $payload, $queueName);
        $this->trackQueue(sprintf('%s.delay.%d', $queueName, $delaySeconds * 1000));

        $this->assertSame('delayed-job', $returnedId);

        // Message must still be in the delay queue, not the main queue.
        $this->assertNull($queue->pop($queueName), 'Job should not be visible before delay elapses');

        // Wait past the TTL plus broker scheduling tolerance.
        sleep($delaySeconds + 1);

        /** @var AmqpJob|null $job */
        $job = $queue->pop($queueName);

        $this->assertInstanceOf(AmqpJob::class, $job, 'Job must be delivered to main queue after delay');
        $this->assertSame($payload, $job->getRawBody());
        $this->assertSame('delayed-job', $job->getJobId());

        $job->delete();
    }

    public function testLaterRawWithZeroDelayPublishesImmediately(): void
    {
        $queueName = $this->uniqueQueueName('laravel-queue-immediate');
        $queue = $this->makeQueue($queueName);

        // Force declaration so the publisher's exchange is bound to the queue.
        $this->assertSame(0, $queue->size($queueName));

        $queue->laterRaw(0, $this->laravelJobPayload(['id' => 'immediate']), $queueName);

        usleep(100000);

        $job = $queue->pop($queueName);
        $this->assertInstanceOf(AmqpJob::class, $job);
        $this->assertSame('immediate', $job->getJobId());

        $job->delete();
    }
}
