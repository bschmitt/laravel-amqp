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
}
