<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\ConfigurationProvider;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Illuminate\Config\Repository;

class ConfigurationProviderTest extends BaseTestCase
{
    public function testResolvesUseAndPropertiesFormat()
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => [
                        'host' => 'rabbitmq.example',
                        'port' => 5672,
                    ],
                ],
            ],
        ]);

        $provider = new ConfigurationProvider($config);

        $this->assertSame('rabbitmq.example', $provider->getProperty('host'));
        $this->assertSame(5672, $provider->getProperty('port'));
    }

    public function testResolvesDefaultAndConnectionsLegacyFormat()
    {
        $config = new Repository([
            'amqp' => [
                'default' => 'staging',
                'connections' => [
                    'staging' => [
                        'host' => 'legacy.example',
                        'port' => 5673,
                    ],
                ],
            ],
        ]);

        $provider = new ConfigurationProvider($config);

        $this->assertSame('legacy.example', $provider->getProperty('host'));
        $this->assertSame(5673, $provider->getProperty('port'));
    }

    public function testMergePropertiesPreservesOriginalConnection()
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => [
                        'host' => 'localhost',
                        'queue' => 'jobs',
                    ],
                ],
            ],
        ]);

        $provider = new ConfigurationProvider($config);
        $provider->mergeProperties(['queue' => 'priority-jobs']);

        $this->assertSame('priority-jobs', $provider->getProperty('queue'));
        $this->assertSame('localhost', $provider->getProperty('host'));

        $provider->mergeProperties(['queue' => 'jobs']);
        $this->assertSame('jobs', $provider->getProperty('queue'));
    }
}
