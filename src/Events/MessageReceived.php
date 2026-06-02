<?php

namespace Bschmitt\Amqp\Events;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Dispatched when the consume pipeline receives a message, before any
 * middleware or handler is invoked.
 */
class MessageReceived
{
    /** @var string */
    public $queue;

    /** @var AMQPMessage */
    public $message;

    /**
     * @param string      $queue
     * @param AMQPMessage $message
     */
    public function __construct(string $queue, AMQPMessage $message)
    {
        $this->queue = $queue;
        $this->message = $message;
    }
}
