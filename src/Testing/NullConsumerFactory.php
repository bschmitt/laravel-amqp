<?php

namespace Bschmitt\Amqp\Testing;

use Bschmitt\Amqp\Contracts\ConsumerFactoryInterface;
use Bschmitt\Amqp\Contracts\ConsumerInterface;

/**
 * Consumer factory used by {@see FakeAmqp} — never connects.
 */
class NullConsumerFactory implements ConsumerFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(array $properties = []): ConsumerInterface
    {
        return new NullConsumer();
    }
}
