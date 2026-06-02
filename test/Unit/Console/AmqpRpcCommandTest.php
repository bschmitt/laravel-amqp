<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpRpcCommand;
use Bschmitt\Amqp\Support\RpcClient;
use Bschmitt\Amqp\Support\RpcLatencyRecorder;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Mockery;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpRpcCommandTest extends CommandTestCase
{
    public function testSuccessfulCallEmitsResponseAndDuration(): void
    {
        $this->amqp->shouldReceive('rpcClient')
            ->once()
            ->with(Mockery::on(function ($props) {
                return is_array($props);
            }))
            ->andReturnUsing(function () {
                return new RpcClient($this->amqp);
            });

        $this->amqp->shouldReceive('rpc')
            ->once()
            ->andReturn('{"pong":true}');

        $this->amqp->shouldReceive('rpcMetrics')
            ->andReturn(new RpcLatencyRecorder());

        $result = $this->runCommand(new AmqpRpcCommand(), [
            'routing' => 'svc.ping',
            'payload' => '{"ok":true}',
            '--timeout' => 5,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('OK', $result['output']);
        $this->assertStringContainsString('pong', $result['output']);
    }

    public function testJsonOutputIncludesMetadata(): void
    {
        $this->amqp->shouldReceive('rpcClient')->once()
            ->andReturnUsing(function () {
                return new RpcClient($this->amqp);
            });
        $this->amqp->shouldReceive('rpc')->once()->andReturn('{"value":42}');
        $this->amqp->shouldReceive('rpcMetrics')->andReturn(new RpcLatencyRecorder());

        $result = $this->runCommand(new AmqpRpcCommand(), [
            'routing' => 'svc.echo',
            'payload' => '{"in":1}',
            '--json' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $payload = json_decode($result['output'], true);
        $this->assertIsArray($payload);
        $this->assertSame('svc.echo', $payload['routing']);
        $this->assertSame(['value' => 42], $payload['response']);
        $this->assertFalse($payload['failed']);
    }

    public function testTimeoutIsReportedAndExitsNonZero(): void
    {
        $this->amqp->shouldReceive('rpcClient')->once()
            ->andReturnUsing(function () {
                return new RpcClient($this->amqp);
            });
        $this->amqp->shouldReceive('rpc')->once()->andReturn(null);
        $this->amqp->shouldReceive('rpcMetrics')->andReturn(new RpcLatencyRecorder());

        $result = $this->runCommand(new AmqpRpcCommand(), [
            'routing' => 'svc.dead',
            'payload' => '{}',
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertTrue($payload['timed_out']);
        $this->assertTrue($payload['failed']);
        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
    }

    public function testRawModeSendsRawPayloadWithoutJson(): void
    {
        $captured = null;
        $this->amqp->shouldReceive('rpcClient')->once()
            ->andReturnUsing(function () {
                return new RpcClient($this->amqp);
            });
        $this->amqp->shouldReceive('rpc')->once()
            ->with('svc.raw', Mockery::on(function ($body) use (&$captured) {
                $captured = $body;
                return true;
            }), Mockery::any(), Mockery::any())
            ->andReturn('plain-response');
        $this->amqp->shouldReceive('rpcMetrics')->andReturn(new RpcLatencyRecorder());

        $result = $this->runCommand(new AmqpRpcCommand(), [
            'routing' => 'svc.raw',
            'payload' => 'hello world',
            '--raw' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertSame('hello world', $captured);
        $this->assertStringContainsString('plain-response', $result['output']);
    }

    public function testExceptionDuringCallReturnsFailure(): void
    {
        $this->amqp->shouldReceive('rpcClient')->once()
            ->andReturnUsing(function () {
                return new RpcClient($this->amqp);
            });
        $this->amqp->shouldReceive('rpc')->once()->andThrow(new \RuntimeException('broker down'));
        $this->amqp->shouldReceive('rpcMetrics')->andReturn(new RpcLatencyRecorder());

        $result = $this->runCommand(new AmqpRpcCommand(), [
            'routing' => 'svc.boom',
            'payload' => '{}',
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('broker down', $result['output']);
    }
}
