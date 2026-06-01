<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Contracts\ConnectionManagerInterface;
use Bschmitt\Amqp\Managers\ResilientConnectionManager;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Mockery as m;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class ResilientConnectionManagerTest extends BaseTestCase
{
    public function testGetChannelReconnectsWhenDisconnected(): void
    {
        $inner = m::mock(ConnectionManagerInterface::class);
        $channel = m::mock(AMQPChannel::class);
        $connection = m::mock(AMQPStreamConnection::class);

        $inner->shouldReceive('isConnected')->andReturn(false, true);
        $inner->shouldReceive('disconnect')->once();
        $inner->shouldReceive('connect')->once();
        $inner->shouldReceive('getChannel')->once()->andReturn($channel);
        $inner->shouldReceive('getConnection')->andReturn($connection);

        $manager = new ResilientConnectionManager($inner, [
            'max_reconnect_attempts' => 1,
            'heartbeat' => 0,
        ]);

        $this->assertSame($channel, $manager->getChannel());
    }

    public function testConnectRetriesOnFailure(): void
    {
        $inner = m::mock(ConnectionManagerInterface::class);
        $inner->shouldReceive('connect')->once()->andThrow(new \RuntimeException('down'));
        $inner->shouldReceive('connect')->once();

        $manager = new ResilientConnectionManager($inner, [
            'max_reconnect_attempts' => 2,
            'reconnect_delay_ms' => 0,
            'heartbeat' => 0,
        ]);

        $manager->connect();
        $this->assertTrue(true);
    }
}
