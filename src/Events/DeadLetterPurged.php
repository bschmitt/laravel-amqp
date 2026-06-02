<?php

namespace Bschmitt\Amqp\Events;

/**
 * Fired by {@see \Bschmitt\Amqp\Support\DeadLetterManager::purge()}.
 */
class DeadLetterPurged
{
    /** @var string */
    public $queue;

    /** @var int */
    public $count;

    /**
     * @param string $queue
     * @param int    $count Number of messages dropped from the DLQ.
     */
    public function __construct(string $queue, int $count)
    {
        $this->queue = $queue;
        $this->count = $count;
    }
}
