<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\ConfigurationProviderInterface;
use Illuminate\Contracts\Config\Repository;

class ConfigurationProvider implements ConfigurationProviderInterface
{
    const REPOSITORY_KEY = 'amqp';

    /**
     * @var array
     */
    protected $properties = [];

    /**
     * @var array
     */
    protected $originalProperties = [];

    /**
     * @param Repository $config
     */
    public function __construct(Repository $config)
    {
        $this->extractProperties($config);
    }

    /**
     * @param Repository $config
     * @return void
     */
    protected function extractProperties(Repository $config): void
    {
        if (!$config->has(self::REPOSITORY_KEY)) {
            return;
        }

        $data = $config->get(self::REPOSITORY_KEY);
        if (!is_array($data)) {
            return;
        }

        $connectionProperties = $this->resolveConnectionProperties($data);
        if ($connectionProperties !== null) {
            $this->originalProperties = $connectionProperties;
            $this->properties = $connectionProperties;
        }
    }

    /**
     * Resolve the active connection block from config (current or legacy layout).
     *
     * @param array $data
     * @return array|null
     */
    protected function resolveConnectionProperties(array $data)
    {
        // Current format (Laravel 8+): use + properties
        if (isset($data['properties'], $data['use']) && is_array($data['properties'])) {
            $connection = $data['use'];
            if (isset($data['properties'][$connection]) && is_array($data['properties'][$connection])) {
                return $data['properties'][$connection];
            }
        }

        // Legacy format: default + connections
        if (isset($data['connections'], $data['default']) && is_array($data['connections'])) {
            $connection = $data['default'];
            if (isset($data['connections'][$connection]) && is_array($data['connections'][$connection])) {
                return $data['connections'][$connection];
            }
        }

        // Single-connection shorthand: flat host/port keys at top level
        if (isset($data['host'], $data['port'])) {
            return $data;
        }

        return null;
    }

    /**
     * @param array $properties
     * @return self
     */
    public function mergeProperties(array $properties): ConfigurationProviderInterface
    {
        $this->properties = array_merge($this->originalProperties, $properties);
        return $this;
    }

    /**
     * @return array
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getProperty(string $key, $default = null)
    {
        return $this->properties[$key] ?? $default;
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getConnectOption(string $key, $default = null)
    {
        $options = $this->getProperty('connect_options', []);

        if (!is_array($options)) {
            return $default;
        }

        return $options[$key] ?? $default;
    }

    /**
     * Get the original Repository instance
     * This is needed for factories that need to create new Repository instances
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    public function getConfigRepository(): \Illuminate\Contracts\Config\Repository
    {
        // Return a new Repository with the current properties
        // This allows factories to create new instances without App facade
        return new \Illuminate\Config\Repository([
            self::REPOSITORY_KEY => [
                'use' => 'production',
                'properties' => [
                    'production' => $this->properties,
                ],
            ],
        ]);
    }
}

