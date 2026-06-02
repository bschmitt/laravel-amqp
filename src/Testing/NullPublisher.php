<?php

namespace Bschmitt\Amqp\Testing;

use Bschmitt\Amqp\Contracts\PublisherInterface;
use Bschmitt\Amqp\Models\Message;

/**
 * No-op publisher used by {@see FakeAmqp}.
 */
class NullPublisher implements PublisherInterface
{
    /**
     * {@inheritdoc}
     */
    public function publish(string $routing, $message, bool $mandatory = false): ?bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function batchBasicPublish(string $routing, $message): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function batchPublish(): void
    {
    }
}
