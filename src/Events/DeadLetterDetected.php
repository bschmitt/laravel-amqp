<?php

namespace Bschmitt\Amqp\Events;

/**
 * Fired by {@see \Bschmitt\Amqp\Support\DeadLetterManager} after a DLQ
 * inspection (`count()`, `peek()`, or `summarize()`) so listeners can wire
 * alerting / Pulse / Telescope without polling.
 */
class DeadLetterDetected
{
    /** @var string */
    public $queue;

    /** @var int */
    public $messageCount;

    /** @var array<string, mixed> */
    public $summary;

    /**
     * @param string               $queue
     * @param int                  $messageCount
     * @param array<string, mixed> $summary       Output of {@see DeadLetterManager::summarize()}; may be empty.
     */
    public function __construct(string $queue, int $messageCount, array $summary = [])
    {
        $this->queue = $queue;
        $this->messageCount = $messageCount;
        $this->summary = $summary;
    }
}
