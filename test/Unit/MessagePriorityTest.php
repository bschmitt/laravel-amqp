<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Exception\Configuration;
use \Mockery;
use Bschmitt\Amqp\Core\Request;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * Test cases for RabbitMQ Message Priority feature
 * 
 * Tests:
 * - x-max-priority: Maximum priority level for queue (0-255)
 * - Message priority property: Priority of individual messages
 * 
 * Reference: https://www.rabbitmq.com/docs/priority
 */
class MessagePriorityTest extends BaseTestCase
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
     * Test that x-max-priority can be configured
     * 
     * According to RabbitMQ docs:
     * - x-max-priority: Maximum priority level for the queue (0-255)
     * - Must be set on queue declaration
     * - Messages with priority > max-priority are treated as max-priority
     */
    public function testQueueDeclareWithMaxPriority()
    {
        $queueName = 'test-queue-priority';
        $maxPriority = 10;
        $expectedQueueProperties = [
            'x-max-priority' => $maxPriority
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
                Mockery::on(function ($arg) use ($maxPriority) {
                    return isset($arg['x-max-priority']) 
                        && $arg['x-max-priority'] === $maxPriority;
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
     * Test that x-priority (alias) can be configured
     * 
     * Some RabbitMQ versions use x-priority instead of x-max-priority
     */
    public function testQueueDeclareWithPriorityAlias()
    {
        $queueName = 'test-queue-priority-alias';
        $maxPriority = 5;
        $expectedQueueProperties = [
            'x-priority' => $maxPriority
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
                Mockery::on(function ($arg) use ($maxPriority) {
                    return isset($arg['x-priority']) 
                        && $arg['x-priority'] === $maxPriority;
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
     * Test that priority works with other queue properties
     */
    public function testQueueDeclareWithPriorityAndOtherProperties()
    {
        $queueName = 'test-queue-priority-combined';
        $expectedQueueProperties = [
            'x-max-priority' => 10,
            'x-max-length' => 100,
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
                    return isset($arg['x-max-priority']) 
                        && $arg['x-max-priority'] === 10
                        && isset($arg['x-max-length'])
                        && $arg['x-max-length'] === 100
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
     * Test different priority levels
     */
    public function testQueueDeclareWithDifferentPriorityLevels()
    {
        $priorityLevels = [1, 5, 10, 50, 255];

        foreach ($priorityLevels as $priority) {
            $queueName = 'test-queue-priority-' . $priority; // Fixed name per priority level
            $expectedQueueProperties = [
                'x-max-priority' => $priority
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
                    Mockery::on(function ($arg) use ($priority) {
                        return isset($arg['x-max-priority']) 
                            && $arg['x-max-priority'] === $priority;
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

            $this->assertNull($thrownException, "Priority level {$priority} should be supported");
        }
    }
}

