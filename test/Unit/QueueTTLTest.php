<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Exception\Configuration;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use \Mockery;
use Bschmitt\Amqp\Core\Request;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * Test cases for RabbitMQ Time-to-Live (TTL) features
 * 
 * Tests:
 * - x-message-ttl: Message TTL in milliseconds
 * - x-expires: Queue TTL in milliseconds
 * 
 * Reference: https://www.rabbitmq.com/docs/ttl
 */
class QueueTTLTest extends BaseTestCase
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
     * Test that x-message-ttl can be configured
     * 
     * According to RabbitMQ docs:
     * - x-message-ttl: Time in milliseconds that a message can remain in the queue
     * - Messages older than TTL are automatically expired
     * - Value must be a non-negative integer
     */
    public function testQueueDeclareWithMessageTTL()
    {
        $queueName = 'test-queue-message-ttl';
        $messageTTL = 60000; // 60 seconds in milliseconds
        $expectedQueueProperties = [
            'x-message-ttl' => $messageTTL
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
                Mockery::on(function ($arg) use ($messageTTL) {
                    return isset($arg['x-message-ttl']) 
                        && $arg['x-message-ttl'] === $messageTTL;
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
     * Test that x-expires can be configured
     * 
     * According to RabbitMQ docs:
     * - x-expires: Time in milliseconds that a queue can remain unused before being deleted
     * - Queue is deleted if unused for the specified time
     * - Value must be a non-negative integer
     */
    public function testQueueDeclareWithQueueExpires()
    {
        $queueName = 'test-queue-expires';
        $queueExpires = 3600000; // 1 hour in milliseconds
        $expectedQueueProperties = [
            'x-expires' => $queueExpires
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
                Mockery::on(function ($arg) use ($queueExpires) {
                    return isset($arg['x-expires']) 
                        && $arg['x-expires'] === $queueExpires;
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
     * Test that both x-message-ttl and x-expires can be set together
     */
    public function testQueueDeclareWithBothTTLProperties()
    {
        $queueName = 'test-queue-both-ttl';
        $messageTTL = 30000; // 30 seconds
        $queueExpires = 1800000; // 30 minutes
        $expectedQueueProperties = [
            'x-message-ttl' => $messageTTL,
            'x-expires' => $queueExpires
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
                Mockery::on(function ($arg) use ($messageTTL, $queueExpires) {
                    return isset($arg['x-message-ttl']) 
                        && $arg['x-message-ttl'] === $messageTTL
                        && isset($arg['x-expires'])
                        && $arg['x-expires'] === $queueExpires;
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
     * Test that TTL properties work with other queue properties
     */
    public function testQueueDeclareWithTTLAndMaxLength()
    {
        $queueName = 'test-queue-ttl-maxlength';
        $expectedQueueProperties = [
            'x-message-ttl' => 60000,
            'x-expires' => 3600000,
            'x-max-length' => 10
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
                    return isset($arg['x-message-ttl']) 
                        && $arg['x-message-ttl'] === 60000
                        && isset($arg['x-expires'])
                        && $arg['x-expires'] === 3600000
                        && isset($arg['x-max-length'])
                        && $arg['x-max-length'] === 10;
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

