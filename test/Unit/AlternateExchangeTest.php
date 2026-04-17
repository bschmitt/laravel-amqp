<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Exception\Configuration;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use \Mockery;
use Bschmitt\Amqp\Core\Request;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * Test cases for alternate exchange feature (alternate-exchange)
 * 
 * According to RabbitMQ docs:
 * - alternate-exchange: Exchange name where unroutable messages are sent
 * - When a message cannot be routed to any queue, it is sent to the alternate exchange
 * - Reference: https://www.rabbitmq.com/docs/ae
 */
class AlternateExchangeTest extends BaseTestCase
{
    private $connectionMock;
    private $channelMock;
    private $requestMock;

    protected function setUp() : void
    {
        parent::setUp();

        $this->channelMock = Mockery::mock(AMQPChannel::class);
        $this->connectionMock = Mockery::mock(AMQPSSLConnection::class);
        $this->requestMock = Mockery::mock(Request::class . '[connect,getChannel]', [$this->configRepository]);

        $this->setProtectedProperty(Request::class, $this->requestMock, 'channel', $this->channelMock);
        $this->setProtectedProperty(Request::class, $this->requestMock, 'connection', $this->connectionMock);
    }

    /**
     * Test that exchange_properties includes alternate-exchange when configured
     */
    public function testExchangeDeclareWithAlternateExchange()
    {
        $exchangeName = 'test-exchange';
        $alternateExchangeName = 'unroutable-exchange';
        $expectedExchangeProperties = [
            'alternate-exchange' => $alternateExchangeName
        ];

        $this->requestMock->mergeProperties([
            'exchange' => $exchangeName,
            'exchange_type' => 'topic',
            'exchange_properties' => $expectedExchangeProperties
        ]);

        $this->requestMock->shouldReceive('connect');

        $this->channelMock->shouldReceive('exchange_declare')
            ->with(
                $exchangeName,
                $this->defaultConfig['exchange_type'],
                $this->defaultConfig['exchange_passive'],
                $this->defaultConfig['exchange_durable'],
                $this->defaultConfig['exchange_auto_delete'],
                $this->defaultConfig['exchange_internal'],
                $this->defaultConfig['exchange_nowait'],
                Mockery::on(function ($arg) use ($alternateExchangeName) {
                    return isset($arg['alternate-exchange']) 
                        && $arg['alternate-exchange'] === $alternateExchangeName;
                })
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

    /**
     * Test that exchange_declare works without alternate-exchange
     */
    public function testExchangeDeclareWithoutAlternateExchange()
    {
        $exchangeName = 'test-exchange';
        $exchangeProperties = [];

        $this->requestMock->mergeProperties([
            'exchange' => $exchangeName,
            'exchange_type' => 'topic',
            'exchange_properties' => $exchangeProperties
        ]);

        $this->requestMock->shouldReceive('connect');

        $this->channelMock->shouldReceive('exchange_declare')
            ->with(
                $exchangeName,
                $this->defaultConfig['exchange_type'],
                $this->defaultConfig['exchange_passive'],
                $this->defaultConfig['exchange_durable'],
                $this->defaultConfig['exchange_auto_delete'],
                $this->defaultConfig['exchange_internal'],
                $this->defaultConfig['exchange_nowait'],
                Mockery::on(function ($arg) {
                    return $arg === null || (is_array($arg) && !isset($arg['alternate-exchange']));
                })
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

    /**
     * Test that alternate-exchange can be combined with other exchange properties
     */
    public function testAlternateExchangeWithOtherProperties()
    {
        $exchangeName = 'test-exchange';
        $alternateExchangeName = 'unroutable-exchange';
        $expectedExchangeProperties = [
            'alternate-exchange' => $alternateExchangeName,
            'x-delayed-type' => 'direct' // Example of another exchange property
        ];

        $this->requestMock->mergeProperties([
            'exchange' => $exchangeName,
            'exchange_type' => 'topic',
            'exchange_properties' => $expectedExchangeProperties
        ]);

        $this->requestMock->shouldReceive('connect');

        $this->channelMock->shouldReceive('exchange_declare')
            ->with(
                $exchangeName,
                $this->defaultConfig['exchange_type'],
                $this->defaultConfig['exchange_passive'],
                $this->defaultConfig['exchange_durable'],
                $this->defaultConfig['exchange_auto_delete'],
                $this->defaultConfig['exchange_internal'],
                $this->defaultConfig['exchange_nowait'],
                Mockery::on(function ($arg) use ($alternateExchangeName) {
                    return isset($arg['alternate-exchange']) 
                        && $arg['alternate-exchange'] === $alternateExchangeName
                        && isset($arg['x-delayed-type'])
                        && $arg['x-delayed-type'] === 'direct';
                })
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
}

