<?php

namespace Bschmitt\Amqp\Contracts;

/**
 * Interface for managing batch messages
 */
interface BatchManagerInterface
{
    /**
     * Add a message to the batch
     *
     * @param string $routing
     * @param mixed $message
     * @return void
     */
    public function add(string $routing, $message): void;

    /**
     * Get all batched messages
     *
     * @return array
     */
    public function getMessages(): array;

    /**
     * Clear all batched messages
     *
     * @return void
     */
    public function clear(): void;

    /**
     * Check if batch is empty
     *
     * @return bool
     */
    public function isEmpty(): bool;

    /**
     * Get the number of messages in batch
     *
     * @return int
     */
    public function count(): int;
}


