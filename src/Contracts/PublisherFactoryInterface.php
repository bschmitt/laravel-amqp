<?php

namespace Bschmitt\Amqp\Contracts;

use Bschmitt\Amqp\Contracts\PublisherInterface;

/**
 * Factory interface for creating Publisher instances
 */
interface PublisherFactoryInterface
{
    /**
     * Create a new publisher instance with optional properties
     *
     * @param array $properties
     * @return PublisherInterface
     */
    public function create(array $properties = []): PublisherInterface;
}


