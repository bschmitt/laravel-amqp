<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Exception\Configuration;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use \Mockery;
use Bschmitt\Amqp\Core\Request;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * Test cases for Stream Queue feature
 * 
 * Stream queues provide:
 * - High throughput for large message volumes
 * - Message replay capability
 * - Append-only log structure
 * - Offset management (automatic, handled by RabbitMQ)
 * - Stream filtering (via consumer logic or Stream API)
 * 
 * Reference: https://www.rabbitmq.com/docs/streams
 */
class StreamQueueTest extends BaseTestCase
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
     * Test that stream queue is declared with correct properties
     */
    public function testStreamQueueDeclaration()
    {
        $queueName = 'test-stream-queue';
        $expectedQueueProperties = [
            'x-queue-type' => 'stream'
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'queue_durable' => true,        // Required for stream
            'queue_exclusive' => false,      // Required for stream
            'queue_auto_delete' => false,   // Required for stream
            'routing' => 'test-routing',
            'queue_properties' => $expectedQueueProperties
        ]);

        $this->channelMock->shouldReceive('exchange_declare');
        $this->requestMock->shouldReceive('connect');

        $this->channelMock->shouldReceive('queue_declare')
            ->with(
                $queueName,
                $this->defaultConfig['queue_passive'],
                true,  // durable (required for stream)
                false, // exclusive (required for stream)
                false, // auto_delete (required for stream)
                $this->defaultConfig['queue_nowait'],
                Mockery::on(function ($arg) {
                    return isset($arg['x-queue-type']) 
                        && $arg['x-queue-type'] === 'stream';
                })
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
     * Test that stream queue requires durable property
     */
    public function testStreamQueueRequiresDurable()
    {
        $queueName = 'test-stream-queue';
        $queueProperties = [
            'x-queue-type' => 'stream'
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'queue_durable' => false, // Should be true for stream
            'queue_exclusive' => false,
            'queue_auto_delete' => false,
            'routing' => 'test-routing',
            'queue_properties' => $queueProperties
        ]);

        $this->channelMock->shouldReceive('exchange_declare');
        $this->requestMock->shouldReceive('connect');

        // Note: RabbitMQ will reject non-durable stream queues
        // This test verifies the configuration is passed correctly
        // Actual validation happens at RabbitMQ level
        $this->channelMock->shouldReceive('queue_declare')
            ->with(
                $queueName,
                $this->defaultConfig['queue_passive'],
                false, // non-durable (will be rejected by RabbitMQ)
                false,
                false,
                $this->defaultConfig['queue_nowait'],
                Mockery::on(function ($arg) {
                    return isset($arg['x-queue-type']) 
                        && $arg['x-queue-type'] === 'stream';
                })
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

        // Configuration passes, but RabbitMQ will reject non-durable stream queues
        $this->assertNull($thrownException);
    }

    /**
     * Test that stream queue can be combined with other properties
     */
    public function testStreamQueueWithOtherProperties()
    {
        $queueName = 'test-stream-queue-combined';
        $expectedQueueProperties = [
            'x-queue-type' => 'stream',
            'x-max-length-bytes' => 1073741824 // 1GB
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'queue_durable' => true,
            'queue_exclusive' => false,
            'queue_auto_delete' => false,
            'routing' => 'test-routing',
            'queue_properties' => $expectedQueueProperties
        ]);

        $this->channelMock->shouldReceive('exchange_declare');
        $this->requestMock->shouldReceive('connect');

        $this->channelMock->shouldReceive('queue_declare')
            ->with(
                $queueName,
                $this->defaultConfig['queue_passive'],
                true,
                false,
                false,
                $this->defaultConfig['queue_nowait'],
                Mockery::on(function ($arg) {
                    return isset($arg['x-queue-type']) 
                        && $arg['x-queue-type'] === 'stream'
                        && isset($arg['x-max-length-bytes'])
                        && $arg['x-max-length-bytes'] === 1073741824;
                })
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
     * Test that stream queue configuration is correct
     */
    public function testStreamQueueConfiguration()
    {
        $queueName = 'test-stream-config';
        $queueProperties = [
            'x-queue-type' => 'stream'
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'queue_durable' => true,
            'queue_exclusive' => false,
            'queue_auto_delete' => false,
            'routing' => 'test-routing',
            'queue_properties' => $queueProperties
        ]);

        $this->channelMock->shouldReceive('exchange_declare');
        $this->requestMock->shouldReceive('connect');

        $this->channelMock->shouldReceive('queue_declare')
            ->with(
                $queueName,
                $this->defaultConfig['queue_passive'],
                true,   // durable
                false,  // exclusive
                false,  // auto_delete
                $this->defaultConfig['queue_nowait'],
                Mockery::on(function ($arg) {
                    return isset($arg['x-queue-type']) 
                        && $arg['x-queue-type'] === 'stream';
                })
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
}

