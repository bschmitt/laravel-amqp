<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Exception\Configuration;
use \Mockery;
use Bschmitt\Amqp\Core\Request;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * Test cases for lazy queue mode feature (x-queue-mode)
 * 
 * According to RabbitMQ docs:
 * - x-queue-mode: Queue mode ('lazy' or 'default')
 * - Lazy queues keep messages on disk, reducing memory usage for large backlogs
 * - Reference: https://www.rabbitmq.com/docs/lazy-queues
 */
class LazyQueueTest extends BaseTestCase
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
     * Test that queue_properties includes x-queue-mode when configured as 'lazy'
     */
    public function testQueueDeclareWithLazyMode()
    {
        $queueName = 'test-queue-lazy';
        $expectedQueueProperties = [
            'x-queue-mode' => 'lazy'
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
                    return isset($arg['x-queue-mode']) 
                        && $arg['x-queue-mode'] === 'lazy';
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
     * Test that queue_properties includes x-queue-mode when configured as 'default'
     */
    public function testQueueDeclareWithDefaultMode()
    {
        $queueName = 'test-queue-default-mode';
        $expectedQueueProperties = [
            'x-queue-mode' => 'default'
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
                    return isset($arg['x-queue-mode']) 
                        && $arg['x-queue-mode'] === 'default';
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
     * Test that x-queue-mode can be combined with other queue properties
     */
    public function testQueueDeclareWithLazyModeAndOtherProperties()
    {
        $queueName = 'test-queue-lazy-combined';
        $expectedQueueProperties = [
            'x-queue-mode' => 'lazy',
            'x-max-length' => 1000,
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
                    return isset($arg['x-queue-mode']) 
                        && $arg['x-queue-mode'] === 'lazy'
                        && isset($arg['x-max-length'])
                        && $arg['x-max-length'] === 1000
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
     * Test that queue can be declared without x-queue-mode (default behavior)
     */
    public function testQueueDeclareWithoutQueueMode()
    {
        $queueName = 'test-queue-no-mode';
        $expectedQueueProperties = [
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
                    // Should not have x-queue-mode when not specified
                    return !isset($arg['x-queue-mode'])
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

