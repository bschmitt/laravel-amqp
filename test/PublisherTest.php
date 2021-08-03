<?php

namespace Bschmitt\Amqp\Test;

use Illuminate\Support\Facades\App;
use \Mockery;
use Bschmitt\Amqp\Publisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class PublisherTest extends BaseTestCase
{
    /**
     * @var Publisher
     */
    private $publisherMock;
    /**
     * @var AMQPSSLConnection
     */
    private $connectionMock;
    /**
     * @var AMQPChannel
     */
    private $channelMock;

    protected function setUp()  : void
    {
        parent::setUp();

        // partial mock of \Bschmitt\Amqp\Publisher
        // we want all methods except [connect] to be real
        $this->publisherMock = Mockery::mock(Publisher::class . '[connect]', [$this->configRepository]);
        // set connection and channel properties
        $this->channelMock = Mockery::mock(AMQPChannel::class);
        $this->connectionMock = Mockery::mock(AMQPSSLConnection::class);
        // channel and connection are both protected and without changing the source this was the only way to mock them
        $this->setProtectedProperty(Publisher::class, $this->publisherMock, 'channel', $this->channelMock);
        $this->setProtectedProperty(Publisher::class, $this->publisherMock, 'connection', $this->connectionMock);

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

        $this->publisherMock
            ->shouldReceive('connect')
            ->once();

        $exceptionThrown = null;
        try {
            $this->publisherMock->setup();
        } catch (\Exception $exception) {
            $exceptionThrown = $exception;
        }

        $this->assertNull($exceptionThrown);
    }

    public function testPublishShouldAChannelMethodWithProperParams()
    {
        $routing = 'routing-key';
        $message = 'sample-message';
        $mandatory = false;

        $this->channelMock->shouldReceive('basic_publish')
            ->with(
                $message,
                $this->defaultConfig['exchange'],
                $routing,
                $mandatory
            )
            ->once();

        $this->assertTrue($this->publisherMock->publish($routing, $message));
    }

    public function testPublishShouldCallAChannelMethodWithCustomExchangeValue()
    {
        $routing = 'routing-key';
        $message = 'sample-message';
        $exchange = 'custom-exchange';
        $mandatory = false;

        $this->publisherMock->mergeProperties(['exchange' => $exchange]);

        $this->channelMock->shouldReceive('basic_publish')
            ->with(
                $message,
                $exchange,
                $routing,
                $mandatory
            )->times(1);

        $this->assertTrue($this->publisherMock->publish($routing, $message));
    }

    public function testBatchPublishWithProperParams()
    {
        $routing = 'routing-key';
        $message = 'sample-message';

        $this->channelMock->shouldReceive('batch_basic_publish')
            ->with(
                $message,
                $this->defaultConfig['exchange'],
                $routing
            )
            ->once();

        $this->channelMock->shouldReceive('publish_batch')
            ->once();

        $thrownException = null;

        try {
            $this->publisherMock->batchBasicPublish($routing, $message);
            $this->publisherMock->batchPublish();
        } catch (\Exception $exception) {
            $thrownException = $exception;
        }

        $this->assertNull($thrownException);
    }

    public function testPublishingToDifferentQueuesAndProperties()
    {
        $this->publisherMock->mergeProperties(['exchange_type' => 'fanout', 'exchange' => 'shared']);
        $this->assertEquals('shared', $this->publisherMock->getProperty('exchange'));
        $this->assertEquals('fanout', $this->publisherMock->getProperty('exchange_type'));

        $this->publisherMock->mergeProperties(['queue' => 'tasks']);
        $this->assertEquals('amq.topic', $this->publisherMock->getProperty('exchange'));
        $this->assertEquals('topic', $this->publisherMock->getProperty('exchange_type'));
        $this->assertEquals('tasks', $this->publisherMock->getProperty('queue'));
    }
}
