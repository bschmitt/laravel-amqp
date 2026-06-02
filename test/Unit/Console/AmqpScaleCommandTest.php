<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpScaleCommand;
use Bschmitt\Amqp\Support\QueueMetrics;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Mockery;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpScaleCommandTest extends CommandTestCase
{
    public function testJsonRecommendationsForSingleQueue(): void
    {
        $metrics = new QueueMetrics('orders', '/', 450, 400, 50, 2, null, null, null, []);
        $this->amqp->shouldReceive('queueMetrics')
            ->with('orders', null, Mockery::any())
            ->once()
            ->andReturn($metrics);

        $result = $this->runCommand(new AmqpScaleCommand(), [
            'queues' => ['orders'],
            '--per-consumer' => 100,
            '--json' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $payload = json_decode($result['output'], true);
        $this->assertIsArray($payload);
        $this->assertCount(1, $payload);
        $this->assertSame('orders', $payload[0]['queue']);
        $this->assertSame(5, $payload[0]['desired_consumers']);
        $this->assertSame('scale_up', $payload[0]['action']);
    }

    public function testHumanTableRendersForMultipleQueues(): void
    {
        $a = new QueueMetrics('orders', '/', 100, 100, 0, 1, null, null, null, []);
        $b = new QueueMetrics('orders.priority', '/', 50, 50, 0, 1, null, null, null, []);

        $this->amqp->shouldReceive('queueMetrics')
            ->with('orders', null, Mockery::any())
            ->once()->andReturn($a);
        $this->amqp->shouldReceive('queueMetrics')
            ->with('orders.priority', null, Mockery::any())
            ->once()->andReturn($b);

        $result = $this->runCommand(new AmqpScaleCommand(), [
            'queues' => ['orders', 'orders.priority'],
            '--per-consumer' => 100,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('orders', $result['output']);
        $this->assertStringContainsString('orders.priority', $result['output']);
        $this->assertStringContainsString('hold', $result['output']);
    }

    public function testKedaFlagEmitsTriggerSpec(): void
    {
        $metrics = new QueueMetrics('orders', '/', 200, 200, 0, 1, null, null, null, []);
        $this->amqp->shouldReceive('queueMetrics')->once()->andReturn($metrics);

        $result = $this->runCommand(new AmqpScaleCommand(), [
            'queues' => ['orders'],
            '--keda' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $payload = json_decode($result['output'], true);
        $this->assertIsArray($payload);
        $this->assertSame('rabbitmq', $payload[0]['type']);
        $this->assertSame('orders', $payload[0]['metadata']['queueName']);
    }

    public function testFailOnScaleUpFlag(): void
    {
        $metrics = new QueueMetrics('orders', '/', 1000, 1000, 0, 1, null, null, null, []);
        $this->amqp->shouldReceive('queueMetrics')->once()->andReturn($metrics);

        $result = $this->runCommand(new AmqpScaleCommand(), [
            'queues' => ['orders'],
            '--per-consumer' => 100,
            '--fail-on-scale-up' => true,
            '--json' => true,
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
    }

    public function testErrorIsRecordedPerQueue(): void
    {
        $this->amqp->shouldReceive('queueMetrics')
            ->with('broken', null, Mockery::any())
            ->once()
            ->andThrow(new \RuntimeException('management api down'));

        $result = $this->runCommand(new AmqpScaleCommand(), [
            'queues' => ['broken'],
            '--json' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $payload = json_decode($result['output'], true);
        $this->assertStringContainsString('management api down', $payload[0]['error']);
    }
}
