<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\MetricsCollector;
use Bschmitt\Amqp\Support\MonitoringDashboard;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Mockery as m;

class MonitoringDashboardTest extends BaseTestCase
{
    public function testSnapshotCombinesMetricsAndPerQueueStats(): void
    {
        $metrics = new MetricsCollector();
        $metrics->incrementPublished('orders');
        $metrics->incrementConsumed('orders');
        $metrics->incrementHandled();

        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('metrics')->andReturn($metrics);
        $amqp->shouldReceive('getQueueStatistics')
            ->with('orders', null, m::any())
            ->andReturn([
                'name' => 'orders',
                'vhost' => '/',
                'messages' => 2,
                'messages_ready' => 1,
                'messages_unacknowledged' => 1,
                'consumers' => 1,
            ]);
        $amqp->shouldReceive('getQueueStatistics')
            ->withNoArgs()
            ->andReturn([['name' => 'orders']]);

        $dashboard = new MonitoringDashboard($amqp, ['orders']);
        $snapshot = $dashboard->snapshot();

        $this->assertSame(1, $snapshot['process']['published']);
        $this->assertSame(1, $snapshot['process']['handled']);
        $this->assertSame(2, $snapshot['queues']['orders']['messages']);
        $this->assertSame(1, $snapshot['overview']['queues']);
        $this->assertArrayHasKey('generated', $snapshot);
    }

    public function testWatchAddsQueueIfMissing(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('metrics')->andReturn(new MetricsCollector());
        $amqp->shouldReceive('getQueueStatistics')->andReturn([]);

        $dashboard = new MonitoringDashboard($amqp);
        $dashboard->watch('orders')->watch('orders');

        $snapshot = $dashboard->snapshot();
        $this->assertArrayHasKey('orders', $snapshot['queues']);
    }
}
