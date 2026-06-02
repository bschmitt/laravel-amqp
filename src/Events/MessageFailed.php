<?php

namespace Bschmitt\Amqp\Events;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Dispatched when a consume handler throws.
 */
class MessageFailed
{
    /** @var string */
    public $queue;

    /** @var AMQPMessage */
    public $message;

    /** @var \Throwable */
    public $exception;

    /**
     * @param string      $queue
     * @param AMQPMessage $message
     * @param \Throwable  $exception
     */
    public function __construct(string $queue, AMQPMessage $message, \Throwable $exception)
    {
        $this->queue = $queue;
        $this->message = $message;
        $this->exception = $exception;
    }
}
