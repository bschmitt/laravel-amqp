<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\MetricsCollector;
use Bschmitt\Amqp\Test\Support\BaseTestCase;

class MetricsCollectorTest extends BaseTestCase
{
    public function testSnapshotTracksCounters(): void
    {
        $metrics = new MetricsCollector();
        $metrics->incrementPublished('orders.created');
        $metrics->incrementPublished('orders.created');
        $metrics->incrementConsumed('orders');
        $metrics->incrementHandled();
        $metrics->incrementFailed('orders');

        $snapshot = $metrics->snapshot();
        $this->assertSame(2, $snapshot['published']);
        $this->assertSame(1, $snapshot['consumed']);
        $this->assertSame(1, $snapshot['handled']);
        $this->assertSame(1, $snapshot['failed']);
        $this->assertSame(2, $snapshot['published_by_routing']['orders.created']);

        $metrics->reset();
        $this->assertSame(0, $metrics->snapshot()['published']);
    }
}
