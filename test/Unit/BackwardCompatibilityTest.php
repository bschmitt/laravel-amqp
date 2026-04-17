<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Request;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Test backward compatibility with existing installations
 */
class BackwardCompatibilityTest extends BaseTestCase
{
    private $connectionMock;
    private $channelMock;
    private $requestMock;

    protected function setUp() : void
    {
        parent::setUp();

        $this->channelMock = \Mockery::mock(AMQPChannel::class);
        $this->connectionMock = \Mockery::mock(AMQPSSLConnection::class);
        $this->requestMock = \Mockery::mock(Request::class . '[connect,getChannel]', [$this->configRepository]);

        $this->setProtectedProperty(Request::class, $this->requestMock, 'channel', $this->channelMock);
        $this->setProtectedProperty(Request::class, $this->requestMock, 'connection', $this->connectionMock);
    }

    /**
     * Test that array queue_properties still work (backward compatibility)
     */
    public function testArrayQueuePropertiesStillWork()
    {
        $queueName = 'test-queue-array';
        $queueProperties = [
            'x-max-length' => 5,
            'x-ha-policy' => ['S', 'all']
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
                \Mockery::type('PhpAmqpLib\Wire\AMQPTable')
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
     * Test that AMQPTable queue_properties work (new format)
     */
    public function testAMQPTableQueuePropertiesWork()
    {
        $queueName = 'test-queue-table';
        $queueProperties = new AMQPTable([
            'x-max-length' => 10
        ]);

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
                \Mockery::type('PhpAmqpLib\Wire\AMQPTable')
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
     * Test that null/empty queue_properties still work
     */
    public function testNullQueuePropertiesStillWork()
    {
        $queueName = 'test-queue-null';

        $this->requestMock->mergeProperties([
            'queue' => $queueName,
            'queue_force_declare' => true,
            'routing' => 'test-routing',
            'queue_properties' => null
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
                null
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
     * Test that exchange_properties support both array and AMQPTable
     */
    public function testExchangePropertiesBackwardCompatibility()
    {
        $exchangeProperties = ['some-property' => 'value'];

        $this->requestMock->mergeProperties([
            'exchange' => 'test-exchange',
            'exchange_properties' => $exchangeProperties
        ]);

        $this->channelMock->shouldReceive('exchange_declare')
            ->with(
                'test-exchange',
                $this->defaultConfig['exchange_type'],
                $this->defaultConfig['exchange_passive'],
                $this->defaultConfig['exchange_durable'],
                $this->defaultConfig['exchange_auto_delete'],
                $this->defaultConfig['exchange_internal'],
                $this->defaultConfig['exchange_nowait'],
                \Mockery::type('PhpAmqpLib\Wire\AMQPTable')
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
}

