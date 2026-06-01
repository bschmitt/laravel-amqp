<?php

namespace Bschmitt\Amqp\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Merges Laravel queue connection config with amqp.php connection properties.
 */
class QueueConfigResolver
{
    /**
     * Keys from queue.php that override AMQP connection properties.
     */
    protected const QUEUE_OVERRIDE_KEYS = [
        'host',
        'port',
        'username',
        'password',
        'vhost',
        'exchange',
        'exchange_type',
        'exchange_passive',
        'exchange_durable',
        'exchange_auto_delete',
        'exchange_internal',
        'exchange_nowait',
        'exchange_properties',
        'queue_force_declare',
        'queue_passive',
        'queue_durable',
        'queue_exclusive',
        'queue_auto_delete',
        'queue_nowait',
        'queue_properties',
        'consumer_tag',
        'consumer_no_local',
        'consumer_no_ack',
        'consumer_exclusive',
        'consumer_nowait',
        'consumer_properties',
        'timeout',
        'persistent',
        'publish_timeout',
        'publisher_confirms',
        'wait_for_confirms',
        'qos',
        'qos_prefetch_size',
        'qos_prefetch_count',
        'qos_a_global',
        'ssl_options',
        'connect_options',
        'routing',
    ];

    /**
     * @param array $queueConfig  config/queue.php connection entry
     * @param Repository $config  Laravel config repository
     * @return array
     */
    public static function resolve(array $queueConfig, Repository $config): array
    {
        $amqpConfig = $config->get('amqp', []);
        if (!is_array($amqpConfig)) {
            $amqpConfig = [];
        }

        $connectionName = $queueConfig['connection'] ?? $amqpConfig['use'] ?? 'production';
        $properties = self::connectionProperties($amqpConfig, $connectionName);

        $overrides = array_intersect_key($queueConfig, array_flip(self::QUEUE_OVERRIDE_KEYS));

        $queueName = $queueConfig['queue'] ?? 'default';

        $properties = array_merge($properties, $overrides, [
            'queue' => $queueName,
            'queue_force_declare' => $overrides['queue_force_declare'] ?? true,
        ]);

        if (empty($properties['routing'])) {
            $properties['routing'] = [$queueName];
        } elseif (!is_array($properties['routing'])) {
            $properties['routing'] = [$properties['routing']];
        }

        return $properties;
    }

    /**
     * @param array $amqpConfig
     * @param string $connectionName
     * @return array
     */
    protected static function connectionProperties(array $amqpConfig, string $connectionName): array
    {
        if (isset($amqpConfig['properties'][$connectionName]) && is_array($amqpConfig['properties'][$connectionName])) {
            return $amqpConfig['properties'][$connectionName];
        }

        if (isset($amqpConfig['connections'][$connectionName]) && is_array($amqpConfig['connections'][$connectionName])) {
            return $amqpConfig['connections'][$connectionName];
        }

        if (isset($amqpConfig['host'], $amqpConfig['port'])) {
            return $amqpConfig;
        }

        return [];
    }

    /**
     * Build a config repository for Publisher/Consumer factories.
     *
     * @param array $properties
     * @return Repository
     */
    public static function toConfigRepository(array $properties): Repository
    {
        return new \Illuminate\Config\Repository([
            ConfigurationProvider::REPOSITORY_KEY => [
                'use' => 'laravel-queue',
                'properties' => [
                    'laravel-queue' => $properties,
                ],
            ],
        ]);
    }
}
