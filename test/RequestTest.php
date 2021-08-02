<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Exception\Configuration;
use \Mockery;
use Bschmitt\Amqp\Request;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

class RequestTest extends BaseTestCase
{
    private $connectionMock;
    private $channelMock;
    private $requestMock;

    protected function setUp()  : void
    {
        parent::setUp();

        $this->channelMock = Mockery::mock(AMQPChannel::class);
        $this->connectionMock = Mockery::mock(AMQPSSLConnection::class);
        // partial mock of \Bschmitt\Amqp\Publisher
        // we want all methods except [connect,getChannel] to be real
        $this->requestMock = Mockery::mock(Request::class . '[connect,getChannel]', [$this->configRepository]);

        // channel and connection are both protected and without changing the source this was the only way to mock them
        $this->setProtectedProperty(Request::class, $this->requestMock, 'channel', $this->channelMock);
        $this->setProtectedProperty(Request::class, $this->requestMock, 'connection', $this->connectionMock);

    }

    public function testIfEmptyExchangeThrowsAnException()
    {
        $this->requestMock->mergeProperties(['exchange' => '']);
        $this->requestMock->shouldReceive('connect');

        $this->expectException(Configuration::class);
        $this->requestMock->setup();
    }

    public function testIfQueueGetsDeclaredAndBoundIfInConfig()
    {
        $queueName = 'amqp-test';
        $routing = 'routing-test';

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'routing' => $routing
        ]);
        $this->channelMock->shouldReceive('exchange_declare');
        $this->requestMock->shouldReceive('connect');

        $this->channelMock->shouldReceive('queue_declare')
            ->with(
                $queueName,
                $this->defaultConfig['queue_passive'],
                $this->defaultConfig['queue_durable'],
                $this->defaultConfig['queue_exclusive'],
                $this->defaultConfig['queue_auto_delete'],
                $this->defaultConfig['queue_nowait'],
                $this->defaultConfig['queue_properties']
            )
            ->andReturn([$queueName, 4])
            ->once();
        $this->channelMock->shouldReceive('queue_bind')
            ->with(
                $queueName,
                $this->defaultConfig['exchange'],
                $routing
            )
            ->once();
        $this->connectionMock->shouldReceive('set_close_on_destruct')->with(true)->once();

        $thrownException = null;
        try {
            $this->requestMock->setup();
        } catch (\Exception $exception) {
            $thrownException = $exception;
        }

        $this->assertNull($thrownException);
    }

    public function testIfQueueGetsDeclaredAndBoundToDifferentRoutingKeysIfInConfig()
    {
        $queueName = 'amqp-test';
        $routing = [
            'routing-test-1',
            'routing-test-2',
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'routing' => $routing
        ]);
        $this->channelMock->shouldReceive('exchange_declare');
        $this->requestMock->shouldReceive('connect');

        $this->channelMock->shouldReceive('queue_declare')
            ->with(
                $queueName,
                $this->defaultConfig['queue_passive'],
                $this->defaultConfig['queue_durable'],
                $this->defaultConfig['queue_exclusive'],
                $this->defaultConfig['queue_auto_delete'],
                $this->defaultConfig['queue_nowait'],
                $this->defaultConfig['queue_properties']
            )
            ->andReturn([$queueName, 4])
            ->once();
        $this->channelMock->shouldReceive('queue_bind')
            ->with(
                $queueName,
                $this->defaultConfig['exchange'],
                $routing[0]
            )
            ->once();
        $this->channelMock->shouldReceive('queue_bind')
            ->with(
                $queueName,
                $this->defaultConfig['exchange'],
                $routing[1]
            )
            ->once();
        $this->connectionMock->shouldReceive('set_close_on_destruct')->with(true)->once();

        $thrownException = null;

        try {
            $this->requestMock->setup();
        } catch (\Exception $exception) {
            $thrownException = $exception;
        }

        $this->assertNull($thrownException);
    }

    public function testQueueMessageCountShouldBeZeroIfQueueinfoIsNotSet()
    {
        $this->assertEquals(0, $this->requestMock->getQueueMessageCount());
    }

    public function testQueueMessageCountShouldReturnMessageCount()
    {
        $messageCount = 4;
        $queueInfo = ['queue-name', $messageCount];
        $this->setProtectedProperty(Request::class, $this->requestMock, 'queueInfo', $queueInfo);
        $this->assertEquals($this->requestMock->getQueueMessageCount(), $messageCount);
    }

    public function testIfChannelAndConnectionAreClosedWhenShutdownIsInvoked()
    {
        $this->channelMock->shouldReceive('close')->once();
        $this->connectionMock->shouldReceive('close')->once();

        $thrownException = null;

        try {
            $this->requestMock::shutDown($this->channelMock, $this->connectionMock);
        } catch (\Exception $exception) {
            $thrownException = $exception;
        }

        $this->assertNull($thrownException);
    }
}
