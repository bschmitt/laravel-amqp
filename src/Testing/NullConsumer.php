<?php

namespace Bschmitt\Amqp\Testing;

use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Closure;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * No-op consumer used by {@see FakeAmqp}.
 */
class NullConsumer implements ConsumerInterface
{
    /**
     * {@inheritdoc}
     */
    public function consume(string $queue, Closure $callback): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function acknowledge(AMQPMessage $message): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function reject(AMQPMessage $message, bool $requeue = false): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function stopWhenProcessed(): void
    {
    }
}
