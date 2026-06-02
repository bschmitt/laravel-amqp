<?php

namespace Bschmitt\Amqp\Events;

/**
 * Fired by {@see \Bschmitt\Amqp\Support\DeadLetterManager::replayTo()} after
 * each replay batch completes.
 */
class DeadLetterReplayed
{
    /** @var string */
    public $queue;

    /** @var string */
    public $targetQueue;

    /** @var int */
    public $count;

    /**
     * @param string $queue
     * @param string $targetQueue
     * @param int    $count Number of messages successfully republished.
     */
    public function __construct(string $queue, string $targetQueue, int $count)
    {
        $this->queue = $queue;
        $this->targetQueue = $targetQueue;
        $this->count = $count;
    }
}
