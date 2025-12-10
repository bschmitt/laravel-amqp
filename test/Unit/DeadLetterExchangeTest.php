<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Exception\Configuration;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use \Mockery;
use Bschmitt\Amqp\Core\Request;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * Test cases for RabbitMQ Dead Letter Exchange (DLX) feature
 * 
 * Tests:
 * - x-dead-letter-exchange: Dead letter exchange name
 * - x-dead-letter-routing-key: Routing key for dead letters
 * 
 * Reference: https://www.rabbitmq.com/docs/dlx
 */
class DeadLetterExchangeTest extends BaseTestCase
{
    private $connectionMock;
    private $channelMock;
    private $requestMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channelMock = Mockery::mock(AMQPChannel::class);
        $this->connectionMock = Mockery::mock(AMQPSSLConnection::class);
        $this->requestMock = Mockery::mock(Request::class . '[connect,getChannel]', [$this->configRepository]);

        $this->setProtectedProperty(Request::class, $this->requestMock, 'channel', $this->channelMock);
        $this->setProtectedProperty(Request::class, $this->requestMock, 'connection', $this->connectionMock);
    }

    /**
     * Test that x-dead-letter-exchange can be configured
     * 
     * According to RabbitMQ docs:
     * - x-dead-letter-exchange: Name of exchange to send dead letters to
     * - Messages are dead-lettered when: rejected, expired, or queue length exceeded
     */
    public function testQueueDeclareWithDeadLetterExchange()
    {
        $queueName = 'test-queue-dlx';
        $dlxExchange = 'dlx-exchange';
        $expectedQueueProperties = [
            'x-dead-letter-exchange' => $dlxExchange
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'routing' => 'test-routing',
            'queue_properties' => $expectedQueueProperties
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
                Mockery::on(function ($arg) use ($dlxExchange) {
                    return isset($arg['x-dead-letter-exchange']) 
                        && $arg['x-dead-letter-exchange'] === $dlxExchange;
                })
            )
            ->andReturn([$queueName, 0])
            ->once();

        $this->channelMock->shouldReceive('queue_bind')->once();
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
     * Test that x-dead-letter-routing-key can be configured
     * 
     * According to RabbitMQ docs:
     * - x-dead-letter-routing-key: Routing key to use when publishing dead letters
     * - If not set, uses the original routing key
     */
    public function testQueueDeclareWithDeadLetterRoutingKey()
    {
        $queueName = 'test-queue-dlx-routing';
        $dlxExchange = 'dlx-exchange';
        $dlxRoutingKey = 'dlx.routing.key';
        $expectedQueueProperties = [
            'x-dead-letter-exchange' => $dlxExchange,
            'x-dead-letter-routing-key' => $dlxRoutingKey
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'routing' => 'test-routing',
            'queue_properties' => $expectedQueueProperties
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
                Mockery::on(function ($arg) use ($dlxExchange, $dlxRoutingKey) {
                    return isset($arg['x-dead-letter-exchange']) 
                        && $arg['x-dead-letter-exchange'] === $dlxExchange
                        && isset($arg['x-dead-letter-routing-key'])
                        && $arg['x-dead-letter-routing-key'] === $dlxRoutingKey;
                })
            )
            ->andReturn([$queueName, 0])
            ->once();

        $this->channelMock->shouldReceive('queue_bind')->once();
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
     * Test that DLX properties work with other queue properties
     */
    public function testQueueDeclareWithDLXAndOtherProperties()
    {
        $queueName = 'test-queue-dlx-combined';
        $expectedQueueProperties = [
            'x-dead-letter-exchange' => 'dlx-exchange',
            'x-dead-letter-routing-key' => 'dlx.key',
            'x-max-length' => 10,
            'x-message-ttl' => 60000
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'routing' => 'test-routing',
            'queue_properties' => $expectedQueueProperties
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
                Mockery::on(function ($arg) {
                    return isset($arg['x-dead-letter-exchange']) 
                        && $arg['x-dead-letter-exchange'] === 'dlx-exchange'
                        && isset($arg['x-dead-letter-routing-key'])
                        && $arg['x-dead-letter-routing-key'] === 'dlx.key'
                        && isset($arg['x-max-length'])
                        && $arg['x-max-length'] === 10
                        && isset($arg['x-message-ttl'])
                        && $arg['x-message-ttl'] === 60000;
                })
            )
            ->andReturn([$queueName, 0])
            ->once();

        $this->channelMock->shouldReceive('queue_bind')->once();
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
     * Test that DLX exchange only (without routing key) works
     * 
     * When routing key is not set, RabbitMQ uses the original routing key
     */
    public function testQueueDeclareWithDLXExchangeOnly()
    {
        $queueName = 'test-queue-dlx-only';
        $dlxExchange = 'dlx-exchange';
        $expectedQueueProperties = [
            'x-dead-letter-exchange' => $dlxExchange
            // No routing key - should use original
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'routing' => 'test.routing.key',
            'queue_properties' => $expectedQueueProperties
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
                Mockery::on(function ($arg) use ($dlxExchange) {
                    return isset($arg['x-dead-letter-exchange']) 
                        && $arg['x-dead-letter-exchange'] === $dlxExchange
                        && !isset($arg['x-dead-letter-routing-key']);
                })
            )
            ->andReturn([$queueName, 0])
            ->once();

        $this->channelMock->shouldReceive('queue_bind')->once();
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

