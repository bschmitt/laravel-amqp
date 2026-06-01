<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpPurgeCommand;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Mockery;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpPurgeCommandTest extends CommandTestCase
{
    public function testForceFlagSkipsConfirmationAndPurges(): void
    {
        $this->amqp->shouldReceive('queuePurge')
            ->once()
            ->with('my-queue', Mockery::any())
            ->andReturn(42);

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'my-queue',
            '--force' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('Purged 42 message(s) from queue [my-queue]', $result['output']);
    }

    public function testConnectionOptionIsForwardedToPurge(): void
    {
        $captured = null;
        $this->amqp->shouldReceive('queuePurge')
            ->once()
            ->andReturnUsing(function ($queue, $props) use (&$captured) {
                $captured = $props;
                return 0;
            });

        $this->runCommand($this->makeCommand(), [
            'queue' => 'q',
            '--force' => true,
            '--connection' => 'staging',
        ]);

        $this->assertSame('staging', $captured['use']);
    }

    public function testPurgeFailureReturnsFailureExitCode(): void
    {
        $this->amqp->shouldReceive('queuePurge')
            ->once()
            ->andThrow(new \RuntimeException('queue not found'));

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'gone',
            '--force' => true,
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('Purge failed', $result['output']);
        $this->assertStringContainsString('queue not found', $result['output']);
    }

    /* ------------------------------------------------------------------ */

    private function makeCommand(): AmqpPurgeCommand
    {
        return new AmqpPurgeCommand($this->amqp);
    }
}
