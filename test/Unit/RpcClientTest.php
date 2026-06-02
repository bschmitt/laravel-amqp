<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\RpcClient;
use Bschmitt\Amqp\Support\RpcLatencyRecorder;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Mockery as m;

class RpcClientTest extends BaseTestCase
{
    public function testCallReturnsResultWhenRpcSucceeds(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('rpc')
            ->once()
            ->with('svc.ping', '{"ok":true}', m::type('array'), 5)
            ->andReturn('{"pong":true}');
        $recorder = new RpcLatencyRecorder();
        $amqp->shouldReceive('rpcMetrics')->andReturn($recorder);

        $client = (new RpcClient($amqp))->asJson()->timeout(5);
        $result = $client->call('svc.ping', ['ok' => true]);

        $this->assertTrue($result->succeeded());
        $this->assertSame(['pong' => true], $result->body());
        $this->assertNotNull($result->durationMs());
        $stats = $recorder->for('svc.ping');
        $this->assertNotNull($stats);
        $this->assertSame(1, $stats['count']);
    }

    public function testCallReportsTimeout(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('rpc')->once()->andReturn(null);
        $recorder = new RpcLatencyRecorder();
        $amqp->shouldReceive('rpcMetrics')->andReturn($recorder);

        $result = (new RpcClient($amqp))->call('svc.ping', 'x');
        $this->assertTrue($result->timedOut());
        $this->assertFalse($result->succeeded());
        $this->assertTrue($result->failed());
        $stats = $recorder->for('svc.ping');
        $this->assertSame(1, $stats['failed']);
    }
}
