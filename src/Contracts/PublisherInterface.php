<?php

namespace Bschmitt\Amqp\Contracts;

use Bschmitt\Amqp\Models\Message;

interface PublisherInterface
{
    /**
     * Publish a message to an exchange
     *
     * @param string $routing
     * @param string|Message $message
     * @param bool $mandatory
     * @return bool|null
     */
    public function publish(string $routing, $message, bool $mandatory = false): ?bool;

    /**
     * Add a message to the batch
     *
     * @param string $routing
     * @param Message|string $message
     * @return void
     */
    public function batchBasicPublish(string $routing, $message): void;

    /**
     * Publish all batched messages
     *
     * @return void
     */
    public function batchPublish(): void;
}


