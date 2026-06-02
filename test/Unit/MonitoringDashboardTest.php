<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\MetricsCollector;
use Bschmitt\Amqp\Support\MonitoringDashboard;
use Bschmitt\Amqp\Support\RpcLatencyRecorder;
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
        $amqp->shouldReceive('rpcMetrics')->andReturn(new RpcLatencyRecorder());
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
        $this->assertSame(2, $snapshot['queues']['orders']['lag']);
        $this->assertSame(1, $snapshot['overview']['queues']);
        $this->assertArrayHasKey('generated', $snapshot);
        $this->assertArrayNotHasKey('rpc', $snapshot);
        $this->assertArrayNotHasKey('lagging', $snapshot);
    }

    public function testWatchAddsQueueIfMissing(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('metrics')->andReturn(new MetricsCollector());
        $amqp->shouldReceive('rpcMetrics')->andReturn(new RpcLatencyRecorder());
        $amqp->shouldReceive('getQueueStatistics')->andReturn([]);

        $dashboard = new MonitoringDashboard($amqp);
        $dashboard->watch('orders')->watch('orders');

        $snapshot = $dashboard->snapshot();
        $this->assertArrayHasKey('orders', $snapshot['queues']);
    }

    public function testLagThresholdsFlagBreachingQueues(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('metrics')->andReturn(new MetricsCollector());
        $amqp->shouldReceive('rpcMetrics')->andReturn(new RpcLatencyRecorder());
        $amqp->shouldReceive('getQueueStatistics')
            ->with('busy', null, m::any())
            ->andReturn([
                'name' => 'busy',
                'messages_ready' => 5000,
                'messages_unacknowledged' => 0,
                'message_stats' => ['deliver_get_details' => ['rate' => 10.0]],
            ]);
        $amqp->shouldReceive('getQueueStatistics')
            ->with('idle', null, m::any())
            ->andReturn([
                'name' => 'idle',
                'messages_ready' => 1,
                'messages_unacknowledged' => 0,
                'message_stats' => ['deliver_get_details' => ['rate' => 10.0]],
            ]);
        $amqp->shouldReceive('getQueueStatistics')->withNoArgs()->andReturn([]);

        $dashboard = (new MonitoringDashboard($amqp, ['busy', 'idle']))
            ->lagThresholds(100);

        $snapshot = $dashboard->snapshot();
        $this->assertTrue($snapshot['queues']['busy']['lagging']);
        $this->assertFalse($snapshot['queues']['idle']['lagging']);
        $this->assertSame(['busy'], $snapshot['lagging']);
    }

    public function testRpcSnapshotIncludedWhenRecorderHasData(): void
    {
        $recorder = new RpcLatencyRecorder();
        $recorder->record('UserService::GetUser', 12.0);

        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('metrics')->andReturn(new MetricsCollector());
        $amqp->shouldReceive('rpcMetrics')->andReturn($recorder);
        $amqp->shouldReceive('getQueueStatistics')->andReturn([]);

        $dashboard = new MonitoringDashboard($amqp);
        $snapshot = $dashboard->snapshot();

        $this->assertArrayHasKey('rpc', $snapshot);
        $this->assertSame(1, $snapshot['rpc']['UserService::GetUser']['count']);
    }

    public function testDeadLetterSnapshotIncludedWhenRequested(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('metrics')->andReturn(new MetricsCollector());
        $amqp->shouldReceive('rpcMetrics')->andReturn(new RpcLatencyRecorder());
        $amqp->shouldReceive('getQueueStatistics')
            ->with('orders.dlq', null, m::any())
            ->andReturn(['name' => 'orders.dlq', 'messages' => 4, 'consumers' => 0]);
        $amqp->shouldReceive('getQueueStatistics')->withNoArgs()->andReturn([]);

        $dlq = m::mock(\Bschmitt\Amqp\Support\DeadLetterManager::class);
        $dlq->shouldReceive('for')->with('orders.dlq')->andReturnSelf();
        $dlq->shouldReceive('withProperties')->andReturnSelf();
        $dlq->shouldReceive('summarize')->andReturn(['sampled' => 0, 'by_reason' => []]);
        $amqp->shouldReceive('deadLetters')->andReturn($dlq);

        $dashboard = (new MonitoringDashboard($amqp))->deadLetters('orders.dlq');
        $snapshot = $dashboard->snapshot();

        $this->assertArrayHasKey('dead_letters', $snapshot);
        $this->assertSame(4, $snapshot['dead_letters']['orders.dlq']['messages']);
        $this->assertSame(0, $snapshot['dead_letters']['orders.dlq']['summary']['sampled']);
    }
}
