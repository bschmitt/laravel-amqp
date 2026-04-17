<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Exception\Configuration;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use \Mockery;
use Bschmitt\Amqp\Core\Request;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * Test cases for exchange type validation and support
 * 
 * Tests all supported RabbitMQ exchange types:
 * - topic: Routing based on pattern matching
 * - direct: Routing based on exact match
 * - fanout: Broadcast to all bound queues
 * - headers: Routing based on message headers
 * 
 * Reference: https://www.rabbitmq.com/docs/exchanges
 */
class ExchangeTypeTest extends BaseTestCase
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
     * Test that topic exchange type is supported
     */
    public function testTopicExchangeType()
    {
        $exchangeName = 'test-topic-exchange';
        
        $this->requestMock->mergeProperties([
            'exchange' => $exchangeName,
            'exchange_type' => 'topic'
        ]);

        $this->channelMock->shouldReceive('exchange_declare')
            ->with(
                $exchangeName,
                'topic',
                $this->defaultConfig['exchange_passive'],
                $this->defaultConfig['exchange_durable'],
                $this->defaultConfig['exchange_auto_delete'],
                $this->defaultConfig['exchange_internal'],
                $this->defaultConfig['exchange_nowait'],
                null
            )
            ->once();

        $this->requestMock->shouldReceive('connect');
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
     * Test that direct exchange type is supported
     */
    public function testDirectExchangeType()
    {
        $exchangeName = 'test-direct-exchange';
        
        $this->requestMock->mergeProperties([
            'exchange' => $exchangeName,
            'exchange_type' => 'direct'
        ]);

        $this->channelMock->shouldReceive('exchange_declare')
            ->with(
                $exchangeName,
                'direct',
                $this->defaultConfig['exchange_passive'],
                $this->defaultConfig['exchange_durable'],
                $this->defaultConfig['exchange_auto_delete'],
                $this->defaultConfig['exchange_internal'],
                $this->defaultConfig['exchange_nowait'],
                null
            )
            ->once();

        $this->requestMock->shouldReceive('connect');
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
     * Test that fanout exchange type is supported
     */
    public function testFanoutExchangeType()
    {
        $exchangeName = 'test-fanout-exchange';
        
        $this->requestMock->mergeProperties([
            'exchange' => $exchangeName,
            'exchange_type' => 'fanout'
        ]);

        $this->channelMock->shouldReceive('exchange_declare')
            ->with(
                $exchangeName,
                'fanout',
                $this->defaultConfig['exchange_passive'],
                $this->defaultConfig['exchange_durable'],
                $this->defaultConfig['exchange_auto_delete'],
                $this->defaultConfig['exchange_internal'],
                $this->defaultConfig['exchange_nowait'],
                null
            )
            ->once();

        $this->requestMock->shouldReceive('connect');
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
     * Test that headers exchange type is supported
     */
    public function testHeadersExchangeType()
    {
        $exchangeName = 'test-headers-exchange';
        
        $this->requestMock->mergeProperties([
            'exchange' => $exchangeName,
            'exchange_type' => 'headers'
        ]);

        $this->channelMock->shouldReceive('exchange_declare')
            ->with(
                $exchangeName,
                'headers',
                $this->defaultConfig['exchange_passive'],
                $this->defaultConfig['exchange_durable'],
                $this->defaultConfig['exchange_auto_delete'],
                $this->defaultConfig['exchange_internal'],
                $this->defaultConfig['exchange_nowait'],
                null
            )
            ->once();

        $this->requestMock->shouldReceive('connect');
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
     * Test that invalid exchange type throws Configuration exception
     */
    public function testInvalidExchangeTypeThrowsException()
    {
        $exchangeName = 'test-invalid-exchange';
        
        $this->requestMock->mergeProperties([
            'exchange' => $exchangeName,
            'exchange_type' => 'invalid-type'
        ]);

        $this->requestMock->shouldReceive('connect');

        $thrownException = null;
        try {
            $this->requestMock->setup();
        } catch (Configuration $exception) {
            $thrownException = $exception;
        }

        $this->assertInstanceOf(Configuration::class, $thrownException);
        $this->assertStringContainsString('Invalid exchange type', $thrownException->getMessage());
        $this->assertStringContainsString('invalid-type', $thrownException->getMessage());
    }

    /**
     * Test that empty exchange type throws Configuration exception
     */
    public function testEmptyExchangeTypeThrowsException()
    {
        $exchangeName = 'test-empty-exchange';
        
        $this->requestMock->mergeProperties([
            'exchange' => $exchangeName,
            'exchange_type' => ''
        ]);

        $this->requestMock->shouldReceive('connect');

        $thrownException = null;
        try {
            $this->requestMock->setup();
        } catch (Configuration $exception) {
            $thrownException = $exception;
        }

        $this->assertInstanceOf(Configuration::class, $thrownException);
        $this->assertStringContainsString('Invalid exchange type', $thrownException->getMessage());
    }

    /**
     * Test that null exchange type throws Configuration exception
     */
    public function testNullExchangeTypeThrowsException()
    {
        $exchangeName = 'test-null-exchange';
        
        $this->requestMock->mergeProperties([
            'exchange' => $exchangeName,
            'exchange_type' => null
        ]);

        $this->requestMock->shouldReceive('connect');

        $thrownException = null;
        try {
            $this->requestMock->setup();
        } catch (Configuration $exception) {
            $thrownException = $exception;
        }

        $this->assertInstanceOf(Configuration::class, $thrownException);
        $this->assertStringContainsString('Invalid exchange type', $thrownException->getMessage());
    }
}

