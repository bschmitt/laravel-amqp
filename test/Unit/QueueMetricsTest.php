<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\QueueMetrics;
use Bschmitt\Amqp\Test\Support\BaseTestCase;

class QueueMetricsTest extends BaseTestCase
{
    public function testFromManagementApiMapsFields(): void
    {
        $metrics = QueueMetrics::fromManagementApi([
            'name' => 'jobs',
            'vhost' => '/',
            'messages' => 42,
            'messages_ready' => 40,
            'messages_unacknowledged' => 2,
            'consumers' => 3,
            'message_stats' => [
                'publish_details' => ['rate' => 1.5],
                'deliver_get_details' => ['rate' => 2.0],
            ],
        ]);

        $this->assertSame('jobs', $metrics->name());
        $this->assertSame(42, $metrics->messageCount());
        $this->assertSame(3, $metrics->consumerCount());
        $this->assertSame(1.5, $metrics->publishRate());
        $this->assertSame(2.0, $metrics->deliverRate());
    }

    public function testLagSumsReadyAndUnacked(): void
    {
        $metrics = QueueMetrics::fromManagementApi([
            'name' => 'jobs',
            'messages' => 12,
            'messages_ready' => 10,
            'messages_unacknowledged' => 2,
            'consumers' => 1,
        ]);

        $this->assertSame(12, $metrics->lag());
    }

    public function testLagSecondsUsesDeliverRate(): void
    {
        $metrics = QueueMetrics::fromManagementApi([
            'messages_ready' => 20,
            'messages_unacknowledged' => 0,
            'message_stats' => ['deliver_get_details' => ['rate' => 4.0]],
        ]);

        $this->assertEqualsWithDelta(5.0, $metrics->lagSeconds(), 0.001);
    }

    public function testLagSecondsIsNullWithoutDeliverRate(): void
    {
        $metrics = QueueMetrics::fromManagementApi([
            'messages_ready' => 10,
            'messages_unacknowledged' => 0,
        ]);

        $this->assertNull($metrics->lagSeconds());
    }

    public function testLagSecondsIsInfWhenBacklogButZeroRate(): void
    {
        $metrics = QueueMetrics::fromManagementApi([
            'messages_ready' => 5,
            'messages_unacknowledged' => 0,
            'message_stats' => ['deliver_get_details' => ['rate' => 0.0]],
        ]);

        $this->assertSame(INF, $metrics->lagSeconds());
    }

    public function testLagSecondsZeroWhenIdle(): void
    {
        $metrics = QueueMetrics::fromManagementApi([
            'messages_ready' => 0,
            'messages_unacknowledged' => 0,
            'message_stats' => ['deliver_get_details' => ['rate' => 0.0]],
        ]);

        $this->assertSame(0.0, $metrics->lagSeconds());
    }

    public function testOldestMessageAgeSecondsUsesHeadTimestamp(): void
    {
        $metrics = QueueMetrics::fromManagementApi([
            'messages_ready' => 1,
            'head_message_timestamp' => 1000,
        ]);

        $this->assertSame(60, $metrics->oldestMessageAgeSeconds(1060));
    }

    public function testOldestMessageAgeSecondsIsNullWhenAbsent(): void
    {
        $metrics = QueueMetrics::fromManagementApi(['messages_ready' => 1]);

        $this->assertNull($metrics->oldestMessageAgeSeconds());
    }

    public function testIsLaggingByBacklog(): void
    {
        $metrics = QueueMetrics::fromManagementApi([
            'messages_ready' => 1500,
            'messages_unacknowledged' => 0,
            'message_stats' => ['deliver_get_details' => ['rate' => 100.0]],
        ]);

        $this->assertTrue($metrics->isLagging(1000));
        $this->assertFalse($metrics->isLagging(2000));
    }

    public function testIsLaggingByTimeToDrain(): void
    {
        $metrics = QueueMetrics::fromManagementApi([
            'messages_ready' => 600,
            'message_stats' => ['deliver_get_details' => ['rate' => 1.0]],
        ]);

        $this->assertTrue($metrics->isLagging(10000, 60.0));
    }

    public function testIsLaggingByAge(): void
    {
        $metrics = QueueMetrics::fromManagementApi([
            'messages_ready' => 1,
            'head_message_timestamp' => time() - 3600,
        ]);

        $this->assertTrue($metrics->isLagging(100, null, 60));
    }

    public function testToArrayContainsLagFields(): void
    {
        $metrics = QueueMetrics::fromManagementApi([
            'name' => 'orders',
            'messages_ready' => 8,
            'messages_unacknowledged' => 2,
            'message_stats' => ['deliver_get_details' => ['rate' => 5.0]],
            'head_message_timestamp' => time() - 30,
        ]);

        $arr = $metrics->toArray();
        $this->assertSame(10, $arr['lag']);
        $this->assertEqualsWithDelta(2.0, $arr['lag_seconds'], 0.001);
        $this->assertIsInt($arr['oldest_message_age_seconds']);
        $this->assertGreaterThanOrEqual(29, $arr['oldest_message_age_seconds']);
    }
}
