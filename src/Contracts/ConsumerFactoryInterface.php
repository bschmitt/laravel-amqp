<?php

namespace Bschmitt\Amqp\Contracts;

use Bschmitt\Amqp\Contracts\ConsumerInterface;

/**
 * Factory interface for creating Consumer instances
 */
interface ConsumerFactoryInterface
{
    /**
     * Create a new consumer instance with optional properties
     *
     * @param array $properties
     * @return ConsumerInterface
     */
    public function create(array $properties = []): ConsumerInterface;
}


