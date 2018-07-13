<?php

namespace Bschmitt\Amqp\Test;

use \Mockery;
use Bschmitt\Amqp\Publisher;
use Illuminate\Config\Repository;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class PublisherTest extends BaseTestCase
{

    private $publisherMock;
    private $connectionMock;
    private $channelMock;

    protected function setUp()
    {
        parent::setUp();

        // partial mock of \Bschmitt\Amqp\Publisher
        // we want all methods except [connect] to be real
        $this->publisherMock = Mockery::mock('\Bschmitt\Amqp\Publisher[connect]', [$this->configRepository]);
        // set connection and channel properties
        $this->channelMock = Mockery::mock('\PhpAmqpLib\Channel\AMQPChannel');
        $this->connectionMock = Mockery::mock('\PhpAmqpLib\Connection\AMQPSSLConnection');
        // channel and connection are both protected and without changing the source this was the only way to mock them
        $this->setProtectedProperty('\Bschmitt\Amqp\Publisher', $this->publisherMock, 'channel', $this->channelMock);
        $this->setProtectedProperty('\Bschmitt\Amqp\Publisher', $this->publisherMock, 'connection', $this->connectionMock);

    }


    public function testSetupPublisher()
    {

        $this->connectionMock->shouldReceive('set_close_on_destruct')->with(true)->times(1);

        $this->channelMock->shouldReceive('exchange_declare')->with(
            $this->defaultConfig['exchange'],
            $this->defaultConfig['exchange_type'],
            $this->defaultConfig['exchange_passive'],
            $this->defaultConfig['exchange_durable'],
            $this->defaultConfig['exchange_auto_delete'],
            $this->defaultConfig['exchange_internal'],
            $this->defaultConfig['exchange_nowait'],
            $this->defaultConfig['exchange_properties']
        )->times(1);

        $this->publisherMock->shouldReceive('connect')->times(1);

        $this->publisherMock->setup();

    }
    
    public function testPublishShouldAChannelMethodWithProperParams()
    {

        $routing = 'routing-key';
        $message = 'sample-message';

        $this->channelMock->shouldReceive('basic_publish')
                          ->with(
                              $message, 
                              $this->defaultConfig['exchange'], 
                              $routing
                          )->times(1);

        $this->publisherMock->publish($routing, $message);

    }

    public function testPublishShouldCallAChannelMethodWithCustomExchangeValue()
    {

        $routing = 'routing-key';
        $message = 'sample-message';
        $exchange = 'custom-exchange';
        $this->publisherMock->mergeProperties(['exchange' => $exchange]);

        $this->channelMock->shouldReceive('basic_publish')
                          ->with(
                              $message, 
                              $exchange, 
                              $routing
                          )->times(1);

        $this->publisherMock->publish($routing, $message);

    }

}
