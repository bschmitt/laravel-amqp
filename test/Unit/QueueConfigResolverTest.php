<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\ConfigurationProvider;
use Bschmitt\Amqp\Support\QueueConfigResolver;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Illuminate\Config\Repository;

/**
 * Unit tests for {@see QueueConfigResolver}.
 *
 * The resolver is the contract between `config/queue.php` (Laravel-shaped)
 * and `config/amqp.php` (package-shaped). Edge cases below cover the
 * fallbacks Laravel users rely on and the override layering.
 */
class QueueConfigResolverTest extends BaseTestCase
{
    public function testResolvesAmqpConnectionProperties(): void
    {
        $amqpConfig = include dirname(__DIR__, 2).'/config/amqp.php';
        $config = new Repository(['amqp' => $amqpConfig]);

        $resolved = QueueConfigResolver::resolve([
            'driver' => 'amqp',
            'connection' => $amqpConfig['use'],
            'queue' => 'laravel-jobs',
        ], $config);

        $this->assertSame('laravel-jobs', $resolved['queue']);
        $this->assertTrue($resolved['queue_force_declare']);
        $this->assertSame(['laravel-jobs'], $resolved['routing']);
        $this->assertSame(
            $amqpConfig['properties'][$amqpConfig['use']]['host'],
            $resolved['host']
        );
    }

    public function testQueueConfigOverridesBrokerProperties(): void
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => [
                        'host' => 'rabbitmq.internal',
                        'port' => 5672,
                        'exchange' => 'amq.topic',
                    ],
                ],
            ],
        ]);

        $resolved = QueueConfigResolver::resolve([
            'connection' => 'production',
            'queue' => 'jobs',
            'host' => 'localhost',
            'exchange' => 'app.jobs',
        ], $config);

        $this->assertSame('localhost', $resolved['host']);
        $this->assertSame('app.jobs', $resolved['exchange']);
        $this->assertSame('jobs', $resolved['queue']);
    }

    public function testFallsBackToAmqpUseWhenConnectionMissingOnQueueConfig(): void
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'staging',
                'properties' => [
                    'staging' => ['host' => 'staging-mq', 'port' => 5672],
                    'production' => ['host' => 'prod-mq', 'port' => 5672],
                ],
            ],
        ]);

        $resolved = QueueConfigResolver::resolve(['queue' => 'jobs'], $config);

        $this->assertSame('staging-mq', $resolved['host']);
    }

    public function testFallsBackToProductionDefaultWhenAmqpUseAndConnectionMissing(): void
    {
        $config = new Repository([
            'amqp' => [
                // 'use' deliberately omitted.
                'properties' => [
                    'production' => ['host' => 'fallback-mq', 'port' => 5672],
                ],
            ],
        ]);

        $resolved = QueueConfigResolver::resolve(['queue' => 'jobs'], $config);

        $this->assertSame('fallback-mq', $resolved['host']);
    }

    public function testReturnsSafeDefaultsWhenAmqpConfigCompletelyMissing(): void
    {
        $config = new Repository([]);

        $resolved = QueueConfigResolver::resolve(['queue' => 'jobs'], $config);

        $this->assertSame('jobs', $resolved['queue']);
        $this->assertSame(['jobs'], $resolved['routing']);
        $this->assertTrue($resolved['queue_force_declare']);
    }

    public function testRoutingOverrideFromQueueConfigIsNormalisedToArray(): void
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => ['production' => ['host' => 'mq']],
            ],
        ]);

        $resolved = QueueConfigResolver::resolve([
            'queue' => 'jobs',
            'routing' => 'jobs.routing.key',
        ], $config);

        $this->assertSame(['jobs.routing.key'], $resolved['routing']);
    }

    public function testQueueFalsesForceDeclareWhenExplicitlyOverridden(): void
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => ['production' => ['host' => 'mq']],
            ],
        ]);

        $resolved = QueueConfigResolver::resolve([
            'queue' => 'jobs',
            'queue_force_declare' => false,
        ], $config);

        $this->assertFalse($resolved['queue_force_declare']);
    }

    public function testToConfigRepositoryProducesProviderCompatibleStructure(): void
    {
        $repo = QueueConfigResolver::toConfigRepository(['host' => 'mq', 'queue' => 'jobs']);

        $provider = new ConfigurationProvider($repo);

        $this->assertSame('mq', $provider->getProperty('host'));
        $this->assertSame('jobs', $provider->getProperty('queue'));
    }
}
