<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Providers\AmqpServiceProvider;
use Bschmitt\Amqp\Queue\AmqpConnector;
use Bschmitt\Amqp\Queue\AmqpQueue;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * Unit tests for {@see AmqpConnector}.
 *
 * The connector is the small adaptor between Laravel's queue manager and
 * AmqpQueue. These tests make sure config flows through unchanged and the
 * returned queue is fully wired (publisher factory + message factory) so it
 * can be used immediately by the framework.
 */
class AmqpConnectorTest extends BaseTestCase
{
    public function testConnectReturnsAmqpQueueWithMergedProperties(): void
    {
        [$connector, $amqpConfig] = $this->buildConnector();

        $queue = $connector->connect([
            'driver' => 'amqp',
            'connection' => $amqpConfig['use'],
            'queue' => 'jobs',
            'retry_after' => 90,
        ]);

        $this->assertInstanceOf(AmqpQueue::class, $queue);
        $this->assertSame('jobs', $queue->getQueue());

        $resolved = $queue->getAmqpProperties();
        $this->assertSame('jobs', $resolved['queue']);
        $this->assertSame(['jobs'], $resolved['routing']);
        $this->assertSame(
            $amqpConfig['properties'][$amqpConfig['use']]['host'],
            $resolved['host']
        );
        $this->assertTrue($resolved['queue_force_declare']);
    }

    public function testConnectHonoursQueueConfigOverridesForBrokerProperties(): void
    {
        [$connector, $amqpConfig] = $this->buildConnector();

        $queue = $connector->connect([
            'driver' => 'amqp',
            'connection' => $amqpConfig['use'],
            'queue' => 'jobs',
            'host' => '10.0.0.1',
            'exchange' => 'override.topic',
        ]);

        $resolved = $queue->getAmqpProperties();
        $this->assertSame('10.0.0.1', $resolved['host']);
        $this->assertSame('override.topic', $resolved['exchange']);
    }

    /**
     * @return array{0: AmqpConnector, 1: array}
     */
    private function buildConnector(): array
    {
        $amqpConfig = include dirname(__DIR__, 2).'/config/amqp.php';

        $container = new Container();
        $container->instance('config', new Repository(['amqp' => $amqpConfig]));

        (new AmqpServiceProvider($container))->register();

        return [new AmqpConnector($container), $amqpConfig];
    }
}
