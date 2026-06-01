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
     * Build a connector against an in-memory AMQP config so the test does not
     * depend on environment variables or the shipped config/amqp.php file.
     *
     * @return array{0: AmqpConnector, 1: array}
     */
    private function buildConnector(): array
    {
        $amqpConfig = $this->fakeAmqpConfig();

        $container = new Container();
        $container->instance('config', new Repository(['amqp' => $amqpConfig]));

        (new AmqpServiceProvider($container))->register();

        return [new AmqpConnector($container), $amqpConfig];
    }

    /**
     * Minimal, deterministic AMQP config used by the connector tests.
     *
     * @return array
     */
    private function fakeAmqpConfig(): array
    {
        return [
            'use' => 'testing',
            'properties' => [
                'testing' => [
                    'host'                 => 'amqp-test-host',
                    'port'                 => 5672,
                    'username'             => 'test-user',
                    'password'             => 'test-pass',
                    'vhost'                => '/',
                    'connect_options'      => [],
                    'ssl_options'          => [],

                    'exchange'             => 'test.topic',
                    'exchange_type'        => 'topic',
                    'exchange_passive'     => false,
                    'exchange_durable'     => true,
                    'exchange_auto_delete' => false,
                    'exchange_internal'    => false,
                    'exchange_nowait'      => false,
                    'exchange_properties'  => [],

                    'queue_force_declare'  => false,
                    'queue_passive'        => false,
                    'queue_durable'        => true,
                    'queue_exclusive'      => false,
                    'queue_auto_delete'    => false,
                    'queue_nowait'         => false,
                    'queue_properties'     => [],

                    'consumer_tag'         => '',
                    'consumer_no_local'    => false,
                    'consumer_no_ack'      => false,
                    'consumer_exclusive'   => false,
                    'consumer_nowait'      => false,
                    'consumer_properties'  => [],

                    'timeout'              => 0,
                    'persistent'           => false,
                    'publish_timeout'      => 30,
                    'publisher_confirms'   => false,
                    'wait_for_confirms'    => true,
                    'qos'                  => false,
                    'qos_prefetch_size'    => 0,
                    'qos_prefetch_count'   => 1,
                    'qos_a_global'         => false,
                ],
            ],
        ];
    }
}
