<?php

namespace Bschmitt\Amqp\Test;

use \Mockery;
use Bschmitt\Amqp\Request;
use Bschmitt\Amqp\Context;


class RequestTest extends BaseTestCase
{

    private $connectionMock;
    private $channelMock;
    private $requestMock;

    protected function setUp()
    {

        parent::setUp();

        $this->channelMock = Mockery::mock('\PhpAmqpLib\Channel\AMQPChannel');
        $this->connectionMock = Mockery::mock('\PhpAmqpLib\Connection\AMQPSSLConnection');
        // partial mock of \Bschmitt\Amqp\Publisher
        // we want all methods except [connect,getChannel] to be real
        $this->requestMock = Mockery::mock('\Bschmitt\Amqp\Request[connect,getChannel]', [$this->configRepository]);

        // channel and connection are both protected and without changing the source this was the only way to mock them
        $this->setProtectedProperty('\Bschmitt\Amqp\Request', $this->requestMock, 'channel', $this->channelMock);
        $this->setProtectedProperty('\Bschmitt\Amqp\Request', $this->requestMock, 'connection', $this->connectionMock);

    }

    /**
     * @expectedException Bschmitt\Amqp\Exception\Configuration
     */
    public function testIfEmptyExchangeThrowsAnException()
    {

        $this->requestMock->mergeProperties(['exchange' => '']);
        $this->requestMock->shouldReceive('connect');

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
                          ->times(1);
        $this->channelMock->shouldReceive('queue_bind')
                          ->with(
                              $queueName,
                              $this->defaultConfig['exchange'],
                              $routing
                            )
                          ->times(1);
        $this->connectionMock->shouldReceive('set_close_on_destruct')->with(true)->times(1);
        $this->requestMock->setup();

    }

    public function testQueueMessageCountShouldBeZeroIfQueueinfoIsNotSet()
    {

        $this->assertEquals($this->requestMock->getQueueMessageCount(), 0);

    }

    public function testQueueMessageCountShouldReturnMessageCount()
    {

        $messageCount = 4;
        $queueInfo = ['queue-name', $messageCount]; 
        $this->setProtectedProperty('\Bschmitt\Amqp\Request', $this->requestMock, 'queueInfo', $queueInfo);
        $this->assertEquals($this->requestMock->getQueueMessageCount(), $messageCount);

    }

    public function testIfChannelAndConnectionAreClosedWhenShutdownIsInvoked()
    {

        $this->channelMock->shouldReceive('close')->times(1);
        $this->connectionMock->shouldReceive('close')->times(1);
        $this->requestMock::shutDown($this->channelMock, $this->connectionMock);

    }

}
