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

    public function testDelayMsRoutesThroughPublishLaterWithDefaultTtlStrategy(): void
    {
        $this->amqp->shouldReceive('publish')->never();
        $this->amqp->shouldReceive('publishLater')
            ->once()
            ->with('order.created', 'hello', 5000, Mockery::on(function ($props) {
                $this->assertSame('ttl', $props['delay_strategy']);
                $this->assertSame('orders', $props['exchange']);
                return true;
            }))
            ->andReturn(true);

        $result = $this->runCommand($this->makeCommand(), [
            'routing-key' => 'order.created',
            '--body' => 'hello',
            '--exchange' => 'orders',
            '--delay-ms' => 5000,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('with delay [5000 ms, strategy=ttl]', $result['output']);
    }

    public function testDelayMsPluginStrategyIsForwarded(): void
    {
        $this->amqp->shouldReceive('publishLater')
            ->once()
            ->with(Mockery::any(), Mockery::any(), 2500, Mockery::on(function ($props) {
                $this->assertSame('plugin', $props['delay_strategy']);
                return true;
            }))
            ->andReturn(true);

        $result = $this->runCommand($this->makeCommand(), [
            'routing-key' => 'evt',
            '--body' => 'x',
            '--delay-ms' => 2500,
            '--delay-strategy' => 'plugin',
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('strategy=plugin', $result['output']);
    }

    public function testNegativeDelayIsRejected(): void
    {
        $this->amqp->shouldReceive('publish')->never();
        $this->amqp->shouldReceive('publishLater')->never();

        $result = $this->runCommand($this->makeCommand(), [
            'routing-key' => 'x',
            '--body' => 'y',
            '--delay-ms' => -1,
        ]);

        $this->assertNotSame(SymfonyCommand::SUCCESS, $result['status']);
    }

    public function testUnknownDelayStrategyIsRejected(): void
    {
        $this->amqp->shouldReceive('publishLater')->never();

        $result = $this->runCommand($this->makeCommand(), [
            'routing-key' => 'x',
            '--body' => 'y',
            '--delay-ms' => 100,
            '--delay-strategy' => 'unknown',
        ]);

        $this->assertNotSame(SymfonyCommand::SUCCESS, $result['status']);
    }

    /* ------------------------------------------------------------------ */

    private function makeCommand(): AmqpPublishCommand
    {
        return new AmqpPublishCommand($this->amqp);
    }
}
