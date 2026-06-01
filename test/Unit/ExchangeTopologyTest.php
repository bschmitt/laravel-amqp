<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\ExchangeTopology;
use Bschmitt\Amqp\Support\QueueProfile;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use InvalidArgumentException;

class ExchangeTopologyTest extends BaseTestCase
{
    public function testDeclarationStepsForMultipleQueues(): void
    {
        $topology = ExchangeTopology::exchange('events', 'topic')
            ->bindQueue('orders.created', 'order.created')
            ->bindQueue('orders.shipped', 'order.shipped', QueueProfile::quorum());

        $steps = $topology->declarationSteps();

        $this->assertCount(2, $steps);
        $this->assertSame('events', $steps[0]['exchange']);
        $this->assertSame('topic', $steps[0]['exchange_type']);
        $this->assertSame('orders.created', $steps[0]['queue']);
        $this->assertSame('order.created', $steps[0]['routing']);
        $this->assertSame('quorum', $steps[1]['queue_properties']['x-queue-type']);
    }

    public function testPropertiesForQueue(): void
    {
        $topology = ExchangeTopology::exchange('app')->bindQueue('jobs');
        $props = $topology->propertiesForQueue('jobs');
        $this->assertSame('jobs', $props['queue']);
        $this->assertSame('jobs', $props['routing']);
    }

    public function testRequiresAtLeastOneBinding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExchangeTopology::exchange('empty')->declarationSteps();
    }

    public function testUnknownQueueRejected(): void
    {
        $topology = ExchangeTopology::exchange('app')->bindQueue('known');
        $this->expectException(InvalidArgumentException::class);
        $topology->propertiesForQueue('missing');
    }
}
