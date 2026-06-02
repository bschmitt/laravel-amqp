<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\RpcClient;
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

        $client = (new RpcClient($amqp))->asJson()->timeout(5);
        $result = $client->call('svc.ping', ['ok' => true]);

        $this->assertTrue($result->succeeded());
        $this->assertSame(['pong' => true], $result->body());
    }

    public function testCallReportsTimeout(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('rpc')->once()->andReturn(null);

        $result = (new RpcClient($amqp))->call('svc.ping', 'x');
        $this->assertTrue($result->timedOut());
        $this->assertFalse($result->succeeded());
    }
}
