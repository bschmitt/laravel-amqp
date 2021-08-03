<?php

namespace Bschmitt\Amqp;

use Illuminate\Contracts\Config\Repository;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
abstract class Context
{
    const REPOSITORY_KEY = 'amqp';

    /**
     * @var array
     */
    protected $properties = [];

    /**
     * @var array
     */
    protected $original_properties = [];

    /**
     * Context constructor.
     *
     * @param Repository $config
     */
    public function __construct(Repository $config)
    {
        $this->extractProperties($config);
    }

    /**
     * @param Repository $config
     */
    protected function extractProperties(Repository $config)
    {
        if ($config->has(self::REPOSITORY_KEY)) {
            $data = $config->get(self::REPOSITORY_KEY);
            $this->original_properties = $data['properties'][$data['use']];
            $this->properties = $this->original_properties;
        }
    }

    /**
     * @param array $properties
     * @return $this
     */
    public function mergeProperties(array $properties) : self
    {
        $this->properties = array_merge($this->original_properties, $properties);

        return $this;
    }

    /**
     * @return array
     */
    public function getProperties() : array
    {
        return $this->properties;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getProperty(string $key)
    {
        return array_key_exists($key, $this->properties) ? $this->properties[$key] : null;
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getConnectOption(string $key, $default = null)
    {
        $options = $this->getProperty('connect_options');

        if (!is_array($options)) {
            return $default;
        }

        return array_key_exists($key, $options) ? $options[$key] : $default;
    }

    /**
     * @return mixed
     */
    abstract public function setup();
}
