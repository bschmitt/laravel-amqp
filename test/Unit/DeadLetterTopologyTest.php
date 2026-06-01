<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\DeadLetterTopology;
use Bschmitt\Amqp\Support\RetryPolicy;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use InvalidArgumentException;

/**
 * Property-bag generation tests for {@see DeadLetterTopology}.
 *
 * The topology is purely declarative; it never touches AMQP. Tests focus on:
 *  - Defaults (queue + DLQ naming, routing key fallback).
 *  - That work queue properties wire up DLX correctly.
 *  - That retry queue properties carry TTL + DLX back to the work queue.
 *  - That plannedRetryDelays() deduplicates per the configured policy.
 */
class DeadLetterTopologyTest extends BaseTestCase
{
    public function testDefaultDlqNameIsDerivedFromQueue(): void
    {
        $topology = DeadLetterTopology::for('orders');

        $this->assertSame('orders', $topology->getQueue());
        $this->assertSame('orders.dlq', $topology->getDlqQueue());
        $this->assertSame('orders', $topology->getRoutingKey(), 'routing key defaults to queue name');
        $this->assertSame('orders.dlq', $topology->getDlqRoutingKey(), 'DLQ routing key defaults to DLQ name');
    }

    public function testEmptyQueueRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeadLetterTopology::for('');
    }

    public function testWorkPropertiesCarryDlxConfiguration(): void
    {
        $topology = DeadLetterTopology::for('jobs')
            ->on('app.events', 'topic')
            ->withRoutingKey('jobs.process');

        $props = $topology->toWorkProperties();

        $this->assertSame('app.events', $props['exchange']);
        $this->assertSame('topic', $props['exchange_type']);
        $this->assertSame('jobs', $props['queue']);
        $this->assertSame(['jobs.process'], $props['routing']);
        $this->assertTrue($props['queue_force_declare']);

        $qp = $props['queue_properties'];
        $this->assertSame('app.events', $qp['x-dead-letter-exchange']);
        $this->assertSame('jobs.dlq', $qp['x-dead-letter-routing-key']);
    }

    public function testWithDlqExchangeOverridesDeadLetterTarget(): void
    {
        $topology = DeadLetterTopology::for('jobs')
            ->on('app.events')
            ->withDlqExchange('dead-letters', 'jobs.failed');

        $props = $topology->toWorkProperties();
        $this->assertSame('dead-letters', $props['queue_properties']['x-dead-letter-exchange']);
        $this->assertSame('jobs.failed', $props['queue_properties']['x-dead-letter-routing-key']);

        $dlq = $topology->toDlqProperties();
        $this->assertSame('dead-letters', $dlq['exchange']);
        $this->assertSame(['jobs.failed'], $dlq['routing']);
        $this->assertSame('jobs.dlq', $dlq['queue']);
    }

    public function testRetryQueuePropertiesCarryTtlAndDlxBackToWorkQueue(): void
    {
        $topology = DeadLetterTopology::for('jobs')->on('amq.topic', 'topic');

        $props = $topology->toRetryQueueProperties(5000);

        $this->assertSame('jobs.retry.5000', $props['queue']);
        $this->assertSame(['jobs.retry.5000'], $props['routing']);
        $this->assertSame('amq.topic', $props['exchange']);

        $qp = $props['queue_properties'];
        $this->assertSame('amq.topic', $qp['x-dead-letter-exchange']);
        $this->assertSame('jobs', $qp['x-dead-letter-routing-key']);
        $this->assertSame(5000, $qp['x-message-ttl']);
        $this->assertGreaterThanOrEqual(10000, $qp['x-expires']);
    }

    public function testNegativeRetryDelayRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeadLetterTopology::for('jobs')->toRetryQueueProperties(-1);
    }

    public function testPlannedRetryDelaysForFixedPolicyDeduplicates(): void
    {
        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(3, 1000));

        $this->assertSame([1000], $topology->plannedRetryDelays());
    }

    public function testPlannedRetryDelaysForExponentialPolicyEnumeratesUniqueValues(): void
    {
        $topology = DeadLetterTopology::for('jobs', RetryPolicy::exponential(4, 100, 2.0));

        $this->assertSame([100, 200, 400, 800], $topology->plannedRetryDelays());
    }

    public function testWithBasePropertiesAreMergedIntoEveryBag(): void
    {
        $topology = DeadLetterTopology::for('jobs')
            ->withBaseProperties(['host' => 'rabbit.local', 'vhost' => '/']);

        $this->assertSame('rabbit.local', $topology->toWorkProperties()['host']);
        $this->assertSame('rabbit.local', $topology->toDlqProperties()['host']);
        $this->assertSame('rabbit.local', $topology->toRetryQueueProperties(1000)['host']);
    }

    public function testCustomQueuePropertiesArePreservedAlongsideDlxKeys(): void
    {
        $topology = DeadLetterTopology::for('jobs')
            ->on('amq.topic')
            ->withQueueProperties(['x-max-length' => 1000, 'x-queue-type' => 'quorum']);

        $qp = $topology->toWorkProperties()['queue_properties'];
        $this->assertSame(1000, $qp['x-max-length']);
        $this->assertSame('quorum', $qp['x-queue-type']);
        $this->assertSame('amq.topic', $qp['x-dead-letter-exchange']);
    }

    public function testRetryQueueNameFollowsConvention(): void
    {
        $topology = DeadLetterTopology::for('orders');
        $this->assertSame('orders.retry.250', $topology->getRetryQueueName(250));
    }
}
