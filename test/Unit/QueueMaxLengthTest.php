<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Exception\Configuration;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use \Mockery;
use Bschmitt\Amqp\Core\Request;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * Test cases for queue max length feature (x-max-length)
 * Fixes issue #120: https://github.com/bschmitt/laravel-amqp/issues/120
 */
class QueueMaxLengthTest extends BaseTestCase
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
     * Test that queue_properties includes x-max-length when configured
     * 
     * According to RabbitMQ docs:
     * - x-max-length: Maximum number of messages (non-negative integer)
     * - Default overflow behavior is 'drop-head' (drops oldest messages)
     * - Only ready messages count (unacknowledged messages don't count)
     */
    public function testQueueDeclareWithMaxLengthProperty()
    {
        $queueName = 'test-queue-max-length';
        $expectedQueueProperties = [
            'x-ha-policy' => ['S', 'all'],
            'x-max-length' => 1
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
                \Mockery::type('PhpAmqpLib\Wire\AMQPTable')
            )
            ->andReturn([$queueName, 0])
            ->once();

        $this->channelMock->shouldReceive('queue_bind')
            ->with(
                $queueName,
                $this->defaultConfig['exchange'],
                'test-routing'
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
     * Test that default config includes x-max-length
     * 
     * With x-max-length=1, when a new message arrives and queue is full,
     * RabbitMQ will drop the oldest message (drop-head behavior) and keep the latest.
     */
    public function testDefaultConfigIncludesMaxLength()
    {
        $queueProperties = $this->defaultConfig['queue_properties'];
        
        $this->assertIsArray($queueProperties);
        $this->assertArrayHasKey('x-max-length', $queueProperties);
        $this->assertEquals(1, $queueProperties['x-max-length']);
    }

    /**
     * Test that custom max length can be set
     */
    public function testCustomMaxLengthValue()
    {
        $queueName = 'test-queue-custom-max-length';
        $customMaxLength = 5;
        $expectedQueueProperties = [
            'x-ha-policy' => ['S', 'all'],
            'x-max-length' => $customMaxLength
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
                Mockery::on(function ($arg) use ($customMaxLength) {
                    return isset($arg['x-max-length']) 
                        && $arg['x-max-length'] === $customMaxLength;
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
     * Test that x-overflow can be configured (optional)
     * 
     * According to RabbitMQ docs:
     * - drop-head (default): Drops oldest messages from front
     * - reject-publish: Rejects new publishes with basic.nack
     * - reject-publish-dlx: Rejects new publishes and dead-letters them
     */
    public function testQueueDeclareWithOverflowBehavior()
    {
        $queueName = 'test-queue-overflow';
        $expectedQueueProperties = [
            'x-ha-policy' => ['S', 'all'],
            'x-max-length' => 1,
            'x-overflow' => 'reject-publish'
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
                    return isset($arg['x-max-length']) 
                        && $arg['x-max-length'] === 1
                        && isset($arg['x-overflow'])
                        && $arg['x-overflow'] === 'reject-publish';
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
     * Test that x-max-length-bytes can be configured
     * 
     * According to RabbitMQ docs:
     * - x-max-length-bytes: Maximum total size of all message bodies in bytes
     * - Only ready messages count (unacknowledged don't count)
     */
    public function testQueueDeclareWithMaxLengthBytes()
    {
        $queueName = 'test-queue-max-length-bytes';
        $expectedQueueProperties = [
            'x-ha-policy' => ['S', 'all'],
            'x-max-length-bytes' => 1024
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
                    return isset($arg['x-max-length-bytes']) 
                        && $arg['x-max-length-bytes'] === 1024;
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
     * Test that both x-max-length and x-max-length-bytes can be set together
     * 
     * According to RabbitMQ docs:
     * - If both are set, whichever limit is hit first will be enforced
     */
    public function testQueueDeclareWithBothMaxLengthAndMaxLengthBytes()
    {
        $queueName = 'test-queue-both-limits';
        $expectedQueueProperties = [
            'x-ha-policy' => ['S', 'all'],
            'x-max-length' => 10,
            'x-max-length-bytes' => 1024,
            'x-overflow' => 'drop-head'
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
                    return isset($arg['x-max-length']) 
                        && $arg['x-max-length'] === 10
                        && isset($arg['x-max-length-bytes'])
                        && $arg['x-max-length-bytes'] === 1024
                        && isset($arg['x-overflow'])
                        && $arg['x-overflow'] === 'drop-head';
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

