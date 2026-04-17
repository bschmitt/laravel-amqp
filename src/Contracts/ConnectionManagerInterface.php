<?php

namespace Bschmitt\Amqp\Contracts;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

interface ConnectionManagerInterface
{
    /**
     * Establish connection to AMQP server
     *
     * @return void
     */
    public function connect(): void;

    /**
     * Get the AMQP channel
     *
     * @return AMQPChannel
     */
    public function getChannel(): AMQPChannel;

    /**
     * Get the AMQP connection
     *
     * @return AMQPStreamConnection
     */
    public function getConnection(): AMQPStreamConnection;

    /**
     * Close channel and connection
     *
     * @return void
     */
    public function disconnect(): void;

    /**
     * Check if connection is established
     *
     * @return bool
     */
    public function isConnected(): bool;
}



