<?php

namespace Bschmitt\Amqp;

use Illuminate\Config\Repository;

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
    protected $methods = [];

    /**
     * Context constructor.
     *
     * @param Repository $config
     */
    public function __construct(Repository $config)
    {
        if ($config->has(self::REPOSITORY_KEY)) {
            $data = $config->get(self::REPOSITORY_KEY);
            $this->properties = $data['properties'][$data['use']];
            $this->methods = $data['methods'];
        }
    }

    /**
     * @param array $properties
     * @return $this
     */
    public function mergeProperties(array $properties)
    {
        $this->properties = array_merge($this->properties, $properties);
        return $this;
    }

    /**
     * @return array
     */
    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getProperty($key)
    {
        return array_key_exists($key, $this->properties) ? $this->properties[$key] : null;
    }

    /**
     * @return array
     */
    public function getRpcMethods()
    {
        return $this->methods;
    }

    /**
     * @param string $key
     * @return RpcHandlerInterface|null
     */
    public function getRpcMethod(string $key)
    {
        return array_key_exists($key, $this->methods) ? $this->methods[$key] : null;
    }

    /**
     * @return mixed
     */
    abstract public function setup();
}
