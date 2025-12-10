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
        if ($config->has(self::REPOSITORY_KEY)) {
            $data = $config->get(self::REPOSITORY_KEY);
            $this->originalProperties = $data['properties'][$data['use']] ?? [];
            $this->properties = $this->originalProperties;
        }
    }

    /**
     * @param array $properties
     * @return self
     */
    public function mergeProperties(array $properties): self
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
}

