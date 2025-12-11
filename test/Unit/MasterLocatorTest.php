<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Exception\Configuration;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use \Mockery;
use Bschmitt\Amqp\Core\Request;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * Test cases for master locator feature (x-queue-master-locator)
 * 
 * According to RabbitMQ docs:
 * - x-queue-master-locator: Master node locator strategy for mirrored queues
 * - Values: 'min-masters', 'client-local', or 'random'
 * - Note: This feature is deprecated. RabbitMQ recommends using Quorum Queues instead
 * - Reference: https://www.rabbitmq.com/docs/ha
 */
class MasterLocatorTest extends BaseTestCase
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
     * Test that queue_properties includes x-queue-master-locator when configured as 'min-masters'
     */
    public function testQueueDeclareWithMinMastersLocator()
    {
        $queueName = 'test-queue-master-locator';
        $expectedQueueProperties = [
            'x-queue-master-locator' => 'min-masters'
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
                    return isset($arg['x-queue-master-locator']) 
                        && $arg['x-queue-master-locator'] === 'min-masters';
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
     * Test that queue_properties includes x-queue-master-locator when configured as 'client-local'
     */
    public function testQueueDeclareWithClientLocalLocator()
    {
        $queueName = 'test-queue-client-local';
        $expectedQueueProperties = [
            'x-queue-master-locator' => 'client-local'
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
                    return isset($arg['x-queue-master-locator']) 
                        && $arg['x-queue-master-locator'] === 'client-local';
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
     * Test that queue_properties includes x-queue-master-locator when configured as 'random'
     */
    public function testQueueDeclareWithRandomLocator()
    {
        $queueName = 'test-queue-random';
        $expectedQueueProperties = [
            'x-queue-master-locator' => 'random'
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
                    return isset($arg['x-queue-master-locator']) 
                        && $arg['x-queue-master-locator'] === 'random';
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
     * Test that queue_properties can include x-queue-master-locator with other properties
     */
    public function testQueueDeclareWithMasterLocatorAndOtherProperties()
    {
        $queueName = 'test-queue-master-locator-combined';
        $expectedQueueProperties = [
            'x-queue-master-locator' => 'min-masters',
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
                    return isset($arg['x-queue-master-locator']) 
                        && $arg['x-queue-master-locator'] === 'min-masters'
                        && isset($arg['x-max-length'])
                        && $arg['x-max-length'] === 100
                        && isset($arg['x-message-ttl'])
                        && $arg['x-message-ttl'] === 60000;
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
     * Test that queue_declare works without x-queue-master-locator
     */
    public function testQueueDeclareWithoutMasterLocator()
    {
        $queueName = 'test-queue-no-master-locator';
        $queueProperties = [
            'x-max-length' => 50
        ];

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'routing' => 'test-routing',
            'queue_properties' => $queueProperties
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
                    return !isset($arg['x-queue-master-locator'])
                        && isset($arg['x-max-length'])
                        && $arg['x-max-length'] === 50;
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

