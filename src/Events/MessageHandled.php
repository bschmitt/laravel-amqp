<?php

namespace Bschmitt\Amqp\Events;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Dispatched after a consume handler completes successfully.
 */
class MessageHandled
{
    /** @var string */
    public $queue;

    /** @var AMQPMessage */
    public $message;

    /** @var float */
    public $durationMs;

    /**
     * @param string      $queue
     * @param AMQPMessage $message
     * @param float       $durationMs
     */
    public function __construct(string $queue, AMQPMessage $message, float $durationMs)
    {
        $this->queue = $queue;
        $this->message = $message;
        $this->durationMs = $durationMs;
    }
}
