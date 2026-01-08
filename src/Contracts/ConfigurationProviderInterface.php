<?php

namespace Bschmitt\Amqp\Contracts;

interface ConfigurationProviderInterface
{
    /**
     * Get a configuration property
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getProperty(string $key, $default = null);

    /**
     * Get all properties
     *
     * @return array
     */
    public function getProperties(): array;

    /**
     * Merge additional properties
     *
     * @param array $properties
     * @return self
     */
    public function mergeProperties(array $properties): ConfigurationProviderInterface;
}



