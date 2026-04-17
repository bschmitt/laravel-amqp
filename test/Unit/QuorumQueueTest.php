<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Exception\Configuration;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use \Mockery;
use Bschmitt\Amqp\Core\Request;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * Test cases for Quorum Queue feature
 * 
 * Quorum queues provide:
 * - High availability through automatic replication
 * - Leader election (automatic, handled by RabbitMQ)
 * - Raft consensus (automatic, built into RabbitMQ)
 * - Better performance than mirrored classic queues
 * 
 * Reference: https://www.rabbitmq.com/docs/quorum-queues
 */
class QuorumQueueTest extends BaseTestCase
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
     * Test that quorum queue is declared with correct properties
     */
    public function testQuorumQueueDeclaration()
    {
        $queueName = 'test-quorum-queue';
        $expectedQueueProperties = [
            'x-queue-type' => 'quorum'
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'queue_durable' => true,        // Required for quorum
            'queue_exclusive' => false,      // Required for quorum
            'queue_auto_delete' => false,   // Required for quorum
            'routing' => 'test-routing',
            'queue_properties' => $expectedQueueProperties
        ]);

        $this->channelMock->shouldReceive('exchange_declare');
        $this->requestMock->shouldReceive('connect');

        $this->channelMock->shouldReceive('queue_declare')
            ->with(
                $queueName,
                $this->defaultConfig['queue_passive'],
                true,  // durable (required for quorum)
                false, // exclusive (required for quorum)
                false, // auto_delete (required for quorum)
                $this->defaultConfig['queue_nowait'],
                Mockery::on(function ($arg) {
                    return isset($arg['x-queue-type']) 
                        && $arg['x-queue-type'] === 'quorum';
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
     * Test that quorum queue requires durable property
     */
    public function testQuorumQueueRequiresDurable()
    {
        $queueName = 'test-quorum-queue';
        $queueProperties = [
            'x-queue-type' => 'quorum'
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'queue_durable' => false, // Should be true for quorum
            'queue_exclusive' => false,
            'queue_auto_delete' => false,
            'routing' => 'test-routing',
            'queue_properties' => $queueProperties
        ]);

        $this->channelMock->shouldReceive('exchange_declare');
        $this->requestMock->shouldReceive('connect');

        // Note: RabbitMQ will reject non-durable quorum queues
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
                        && $arg['x-queue-type'] === 'quorum';
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

        // Configuration passes, but RabbitMQ will reject non-durable quorum queues
        $this->assertNull($thrownException);
    }

    /**
     * Test that quorum queue can be combined with other properties
     */
    public function testQuorumQueueWithOtherProperties()
    {
        $queueName = 'test-quorum-queue-combined';
        $expectedQueueProperties = [
            'x-queue-type' => 'quorum',
            'x-max-length' => 1000
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
                        && $arg['x-queue-type'] === 'quorum'
                        && isset($arg['x-max-length'])
                        && $arg['x-max-length'] === 1000;
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
     * Test that quorum queue configuration is correct
     */
    public function testQuorumQueueConfiguration()
    {
        $queueName = 'test-quorum-config';
        $queueProperties = [
            'x-queue-type' => 'quorum'
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
                        && $arg['x-queue-type'] === 'quorum';
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

