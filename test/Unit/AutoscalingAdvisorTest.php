<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\AutoscalingAdvisor;
use Bschmitt\Amqp\Support\QueueMetrics;
use PHPUnit\Framework\TestCase;

class AutoscalingAdvisorTest extends TestCase
{
    public function testScalesUpFromDepth(): void
    {
        $advice = (new AutoscalingAdvisor())
            ->messagesPerConsumer(100)
            ->advise(['name' => 'orders', 'messages' => 450, 'consumers' => 2]);

        $this->assertSame(5, $advice['desired_consumers']);
        $this->assertSame('scale_up', $advice['action']);
        $this->assertNotEmpty($advice['reasons']);
    }

    public function testRespectsMinAndMax(): void
    {
        $advice = (new AutoscalingAdvisor())
            ->messagesPerConsumer(100)
            ->minReplicas(2)
            ->maxReplicas(4)
            ->advise(['name' => 'orders', 'messages' => 5000, 'consumers' => 0]);

        $this->assertSame(4, $advice['desired_consumers']);
        $this->assertSame('scale_up', $advice['action']);
    }

    public function testHonoursLagThreshold(): void
    {
        $advice = (new AutoscalingAdvisor())
            ->messagesPerConsumer(100)
            ->maxLagSeconds(15.0)
            ->advise([
                'name' => 'orders',
                'messages' => 10,
                'consumers' => 2,
                'lag_seconds' => 30.0,
            ]);

        $this->assertSame(3, $advice['desired_consumers']);
        $this->assertSame('scale_up', $advice['action']);
    }

    public function testScaleDownGracePreventsDrop(): void
    {
        $advice = (new AutoscalingAdvisor())
            ->messagesPerConsumer(100)
            ->scaleDownGrace(10)
            ->advise(['name' => 'orders', 'messages' => 5, 'consumers' => 3]);

        $this->assertSame(3, $advice['desired_consumers']);
        $this->assertSame('hold', $advice['action']);
    }

    public function testReturnsHoldWhenAtTarget(): void
    {
        $advice = (new AutoscalingAdvisor())
            ->messagesPerConsumer(100)
            ->advise(['name' => 'orders', 'messages' => 100, 'consumers' => 1]);

        $this->assertSame(1, $advice['desired_consumers']);
        $this->assertSame('hold', $advice['action']);
    }

    public function testAcceptsQueueMetricsInstance(): void
    {
        $metrics = new QueueMetrics('orders', '/', 250, 200, 50, 2, null, null, null, []);

        $advice = (new AutoscalingAdvisor())
            ->messagesPerConsumer(100)
            ->advise($metrics);

        $this->assertSame('orders', $advice['queue']);
        $this->assertSame(3, $advice['desired_consumers']);
    }

    public function testKedaTriggerShape(): void
    {
        $advice = (new AutoscalingAdvisor())
            ->messagesPerConsumer(100)
            ->minReplicas(1)
            ->maxReplicas(5)
            ->advise(['name' => 'orders', 'messages' => 200, 'consumers' => 1, 'vhost' => '/prod']);

        $this->assertSame('rabbitmq', $advice['keda']['type']);
        $this->assertSame('orders', $advice['keda']['metadata']['queueName']);
        $this->assertSame('/prod', $advice['keda']['metadata']['vhostName']);
        $this->assertSame('100', $advice['keda']['metadata']['value']);
        $this->assertSame(5, $advice['keda']['spec']['maxReplicaCount']);
    }
}
