<?php

namespace Bschmitt\Amqp\Managers;

use Bschmitt\Amqp\Contracts\BatchManagerInterface;

/**
 * Manages batch messages for publishing
 */
class BatchManager implements BatchManagerInterface
{
    /**
     * @var array
     */
    protected $messages = [];

    /**
     * Add a message to the batch
     *
     * @param string $routing
     * @param mixed $message
     * @return void
     */
    public function add(string $routing, $message): void
    {
        $this->messages[] = [
            'routing' => $routing,
            'message' => $message,
        ];
    }

    /**
     * Get all batched messages
     *
     * @return array
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Clear all batched messages
     *
     * @return void
     */
    public function clear(): void
    {
        $this->messages = [];
    }

    /**
     * Check if batch is empty
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->messages);
    }

    /**
     * Get the number of messages in batch
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->messages);
    }
}


