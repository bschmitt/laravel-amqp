<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpInspectCommand;
use Bschmitt\Amqp\Support\QueueMetrics;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Mockery;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpInspectCommandTest extends CommandTestCase
{
    public function testMissingQueueArgumentReturnsInvalid(): void
    {
        $command = new AmqpInspectCommand();
        // Symfony will reject before reaching handle() — but if not, ensure we don't blow up.
        $this->expectException(\Throwable::class);
        $this->runCommand($command, []);
    }

    public function testSnapshotRendersTableForEachQueue(): void
    {
        $metrics = new QueueMetrics('orders', '/', 5, 4, 1, 2, 1.5, 2.5, null, []);

        $this->amqp->shouldReceive('queueMetrics')
            ->with('orders', null, Mockery::any())
            ->once()
            ->andReturn($metrics);

        $result = $this->runCommand(new AmqpInspectCommand(), [
            'queues' => ['orders'],
            '--iterations' => 1,
            '--interval' => 0,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('orders', $result['output']);
        $this->assertStringContainsString('1.5/s', $result['output']);
        $this->assertStringContainsString('2.5/s', $result['output']);
    }

    public function testJsonSnapshotIsParsable(): void
    {
        $metrics = new QueueMetrics('orders', '/', 10, 8, 2, 1, 0.5, 1.0, null, []);

        $this->amqp->shouldReceive('queueMetrics')
            ->with('orders', '/myvhost', Mockery::any())
            ->once()
            ->andReturn($metrics);

        $result = $this->runCommand(new AmqpInspectCommand(), [
            'queues' => ['orders'],
            '--vhost' => '/myvhost',
            '--iterations' => 1,
            '--interval' => 0,
            '--json' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $payload = json_decode($result['output'], true);
        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['iteration']);
        $this->assertCount(1, $payload['queues']);
        $this->assertSame('orders', $payload['queues'][0]['name']);
        $this->assertSame(10, $payload['queues'][0]['messages']);
    }

    public function testFailureOnOneQueueDoesNotBreakOthers(): void
    {
        $metrics = new QueueMetrics('orders', '/', 1, 1, 0, 1, null, null, null, []);

        $this->amqp->shouldReceive('queueMetrics')
            ->with('orders', null, Mockery::any())
            ->once()
            ->andReturn($metrics);

        $this->amqp->shouldReceive('queueMetrics')
            ->with('broken', null, Mockery::any())
            ->once()
            ->andThrow(new \RuntimeException('management API down'));

        $result = $this->runCommand(new AmqpInspectCommand(), [
            'queues' => ['orders', 'broken'],
            '--iterations' => 1,
            '--interval' => 0,
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertCount(2, $payload['queues']);
        $this->assertSame('orders', $payload['queues'][0]['name']);
        $this->assertArrayHasKey('error', $payload['queues'][1]);
        $this->assertStringContainsString('management API down', $payload['queues'][1]['error']);
    }
}
