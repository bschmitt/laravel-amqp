<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpPublishCommand;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Mockery;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpPublishCommandTest extends CommandTestCase
{
    public function testPublishesMessageWithBasicArguments(): void
    {
        $this->amqp->shouldReceive('publish')
            ->once()
            ->with('order.created', 'hello', Mockery::on(function ($props) {
                $this->assertSame('orders', $props['exchange']);
                $this->assertSame(5, $props['priority']);
                return true;
            }))
            ->andReturn(true);

        $result = $this->runCommand($this->makeCommand(), [
            'routing-key' => 'order.created',
            '--body' => 'hello',
            '--exchange' => 'orders',
            '--priority' => 5,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('Published 5 byte(s) to routing key [order.created]', $result['output']);
    }

    public function testHeadersJsonIsDecodedIntoApplicationHeaders(): void
    {
        $this->amqp->shouldReceive('publish')
            ->once()
            ->with(Mockery::any(), Mockery::any(), Mockery::on(function ($props) {
                $this->assertSame(['X-Source' => 'cli', 'X-Tenant' => 7], $props['application_headers']);
                $this->assertSame('corr-1', $props['correlation_id']);
                return true;
            }))
            ->andReturn(true);

        $this->runCommand($this->makeCommand(), [
            'routing-key' => 'foo.bar',
            '--body' => 'x',
            '--headers' => '{"X-Source":"cli","X-Tenant":7}',
            '--correlation-id' => 'corr-1',
        ]);
    }

    public function testMandatoryFlagIsForwarded(): void
    {
        $this->amqp->shouldReceive('publish')
            ->once()
            ->with(Mockery::any(), Mockery::any(), Mockery::on(function ($props) {
                $this->assertTrue($props['mandatory']);
                return true;
            }))
            ->andReturn(true);

        $this->runCommand($this->makeCommand(), [
            'routing-key' => 'x',
            '--body' => 'y',
            '--mandatory' => true,
        ]);
    }

    public function testInvalidHeadersJsonFails(): void
    {
        $this->amqp->shouldReceive('publish')->never();

        $result = $this->runCommand($this->makeCommand(), [
            'routing-key' => 'x',
            '--body' => 'y',
            '--headers' => 'not-json',
        ]);

        $this->assertNotSame(SymfonyCommand::SUCCESS, $result['status']);
    }

    public function testFileOptionReadsBodyFromDisk(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'amqp-pub-');
        file_put_contents($tmp, 'from-file-body');

        try {
            $this->amqp->shouldReceive('publish')
                ->once()
                ->with(Mockery::any(), 'from-file-body', Mockery::any())
                ->andReturn(true);

            $result = $this->runCommand($this->makeCommand(), [
                'routing-key' => 'evt',
                '--file' => $tmp,
            ]);

            $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testPublishExceptionReturnsFailure(): void
    {
        $this->amqp->shouldReceive('publish')
            ->once()
            ->andThrow(new \RuntimeException('broker exploded'));

        $result = $this->runCommand($this->makeCommand(), [
            'routing-key' => 'x',
            '--body' => 'y',
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('Publish failed', $result['output']);
        $this->assertStringContainsString('broker exploded', $result['output']);
    }

    /* ------------------------------------------------------------------ */

    private function makeCommand(): AmqpPublishCommand
    {
        return new AmqpPublishCommand($this->amqp);
    }
}
