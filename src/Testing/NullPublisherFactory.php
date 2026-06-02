<?php

namespace Bschmitt\Amqp\Testing;

use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Contracts\PublisherInterface;

/**
 * Publisher factory used by {@see FakeAmqp} — never connects.
 */
class NullPublisherFactory implements PublisherFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(array $properties = []): PublisherInterface
    {
        return new NullPublisher();
    }
}
