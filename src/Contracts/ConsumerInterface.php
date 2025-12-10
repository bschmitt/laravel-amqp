<?php

namespace Bschmitt\Amqp\Contracts;

use Closure;
use PhpAmqpLib\Message\AMQPMessage;

interface ConsumerInterface
{
    /**
     * Consume messages from a queue
     *
     * @param string $queue
     * @param Closure $callback
     * @return bool
     */
    public function consume(string $queue, Closure $callback): bool;

    /**
     * Acknowledge a message
     *
     * @param AMQPMessage $message
     * @return void
     */
    public function acknowledge(AMQPMessage $message): void;

    /**
     * Reject a message
     *
     * @param AMQPMessage $message
     * @param bool $requeue
     * @return void
     */
    public function reject(AMQPMessage $message, bool $requeue = false): void;

    /**
     * Stop consumer when no message is left
     *
     * @return void
     */
    public function stopWhenProcessed(): void;
}



