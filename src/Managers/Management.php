<?php

namespace Bschmitt\Amqp\Managers;

use Bschmitt\Amqp\Contracts\ConfigurationProviderInterface;
use Bschmitt\Amqp\Contracts\ConnectionManagerInterface;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Management operations for queues and exchanges
 * 
 * Provides methods for:
 * - Queue unbind
 * - Exchange unbind
 * - Queue purge
 * - Queue delete
 * - Exchange delete
 */
class Management
{
    /**
     * @var ConfigurationProviderInterface
     */
    protected $config;

    /**
     * @var ConnectionManagerInterface
     */
    protected $connectionManager;

    /**
     * @param ConfigurationProviderInterface $config
     * @param ConnectionManagerInterface $connectionManager
     */
    public function __construct(
        ConfigurationProviderInterface $config,
        ConnectionManagerInterface $connectionManager
    ) {
        $this->config = $config;
        $this->connectionManager = $connectionManager;
    }

    /**
     * Unbind a queue from an exchange
     *
     * @param string $queue Queue name
     * @param string $exchange Exchange name
     * @param string $routingKey Routing key (optional, defaults to empty string)
     * @param array|null $arguments Additional arguments (optional)
     * @return void
     */
    public function queueUnbind(string $queue, string $exchange, string $routingKey = '', ?array $arguments = null): void
    {
        $channel = $this->connectionManager->getChannel();
        
        $amqpArguments = null;
        if ($arguments !== null && !empty($arguments)) {
            $amqpArguments = new AMQPTable($arguments);
        }

        $channel->queue_unbind($queue, $exchange, $routingKey, $amqpArguments);
    }

    /**
     * Unbind an exchange from another exchange
     *
     * @param string $destination Destination exchange name
     * @param string $source Source exchange name
     * @param string $routingKey Routing key (optional, defaults to empty string)
     * @param array|null $arguments Additional arguments (optional)
     * @return void
     */
    public function exchangeUnbind(string $destination, string $source, string $routingKey = '', ?array $arguments = null): void
    {
        $channel = $this->connectionManager->getChannel();
        
        $amqpArguments = null;
        if ($arguments !== null && !empty($arguments)) {
            $amqpArguments = new AMQPTable($arguments);
        }

        $channel->exchange_unbind($destination, $source, $routingKey, false, $amqpArguments);
    }

    /**
     * Purge all messages from a queue
     *
     * @param string $queue Queue name
     * @return int Number of messages purged
     */
    public function queuePurge(string $queue): int
    {
        $channel = $this->connectionManager->getChannel();
        return $channel->queue_purge($queue);
    }

    /**
     * Delete a queue
     *
     * @param string $queue Queue name
     * @param bool $ifUnused Only delete if queue has no consumers (default: false)
     * @param bool $ifEmpty Only delete if queue is empty (default: false)
     * @return int Number of messages deleted with the queue
     */
    public function queueDelete(string $queue, bool $ifUnused = false, bool $ifEmpty = false): int
    {
        $channel = $this->connectionManager->getChannel();
        return $channel->queue_delete($queue, $ifUnused, $ifEmpty);
    }

    /**
     * Delete an exchange
     *
     * @param string $exchange Exchange name
     * @param bool $ifUnused Only delete if exchange is not in use (default: false)
     * @return void
     */
    public function exchangeDelete(string $exchange, bool $ifUnused = false): void
    {
        $channel = $this->connectionManager->getChannel();
        $channel->exchange_delete($exchange, $ifUnused);
    }

    /**
     * Get the connection manager
     *
     * @return ConnectionManagerInterface
     */
    public function getConnectionManager(): ConnectionManagerInterface
    {
        return $this->connectionManager;
    }
}

