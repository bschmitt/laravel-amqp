<?php

namespace Bschmitt\Amqp\Core;

use Closure;
use Bschmitt\Amqp\Contracts\PublisherInterface;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Contracts\ConsumerFactoryInterface;
use Bschmitt\Amqp\Contracts\BatchManagerInterface;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Managers\ConnectionManager;
use Bschmitt\Amqp\Models\Message;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Amqp
{
    /**
     * @var PublisherFactoryInterface
     */
    protected $publisherFactory;

    /**
     * @var ConsumerFactoryInterface
     */
    protected $consumerFactory;

    /**
     * @var MessageFactory
     */
    protected $messageFactory;

    /**
     * @var BatchManagerInterface
     */
    protected $batchManager;

    /**
     * @param PublisherFactoryInterface $publisherFactory
     * @param ConsumerFactoryInterface $consumerFactory
     * @param MessageFactory $messageFactory
     * @param BatchManagerInterface $batchManager
     */
    public function __construct(
        PublisherFactoryInterface $publisherFactory,
        ConsumerFactoryInterface $consumerFactory,
        MessageFactory $messageFactory,
        BatchManagerInterface $batchManager
    ) {
        $this->publisherFactory = $publisherFactory;
        $this->consumerFactory = $consumerFactory;
        $this->messageFactory = $messageFactory;
        $this->batchManager = $batchManager;
    }

    /**
     * @param string $routing
     * @param mixed  $message
     * @param array  $properties
     *
     * @return bool|null
     */
    public function publish(string $routing, $message, array $properties = []): ?bool
    {
        $properties['routing'] = $routing;
        $publisher = $this->publisherFactory->create($properties);

        try {
            $applicationHeaders = $properties['application_headers'] ?? [];
            $message = $this->messageFactory->create($message, $applicationHeaders);
            $mandatory = (bool) ($properties['mandatory'] ?? false);

            return $publisher->publish($routing, $message, $mandatory);
        } finally {
            $this->disconnectPublisher($publisher);
        }
    }

    /**
     * @param string $routing
     * @param mixed  $message
     * @return void
     */
    public function batchBasicPublish(string $routing, $message): void
    {
        $this->batchManager->add($routing, $message);
    }

    /**
     * @param array $properties
     * @return void
     */
    public function batchPublish(array $properties = []): void
    {
        if ($this->batchManager->isEmpty()) {
            return;
        }

        $publisher = $this->publisherFactory->create($properties);

        try {
            foreach ($this->batchManager->getMessages() as $messageData) {
                if (!isset($messageData['routing']) || !isset($messageData['message'])) {
                    continue;
                }

                $message = $this->messageFactory->create($messageData['message']);
                $publisher->batchBasicPublish($messageData['routing'], $message);
            }

            $publisher->batchPublish();
            $this->batchManager->clear();
        } finally {
            $this->disconnectPublisher($publisher);
        }
    }

    /**
     * @param string  $queue
     * @param Closure $callback
     * @param array   $properties
     * @return bool
     */
    public function consume(string $queue, Closure $callback, array $properties = []): bool
    {
        $properties['queue'] = $queue;
        $consumer = $this->consumerFactory->create($properties);

        try {
            return $consumer->consume($queue, $callback);
        } finally {
            $this->disconnectConsumer($consumer);
        }
    }

    /**
     * @param string $body
     * @param array  $properties
     * @return Message
     */
    public function message(string $body, array $properties = []): Message
    {
        return $this->messageFactory->createWithProperties($body, $properties);
    }

    /**
     * Disconnect publisher resources
     *
     * @param PublisherInterface $publisher
     * @return void
     */
    protected function disconnectPublisher(PublisherInterface $publisher): void
    {
        // Check if publisher has a connection manager (new architecture)
        if ($publisher instanceof \Bschmitt\Amqp\Core\Publisher) {
            $connectionManager = $publisher->getConnectionManager();
            if ($connectionManager !== null) {
                $connectionManager->disconnect();
                return;
            }
        }

        // Fallback: use Request::shutdown for backward compatibility
        if (method_exists($publisher, 'getChannel') && method_exists($publisher, 'getConnection')) {
            \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
        }
    }

    /**
     * Disconnect consumer resources
     *
     * @param ConsumerInterface $consumer
     * @return void
     */
    protected function disconnectConsumer(ConsumerInterface $consumer): void
    {
        // Check if consumer has a connection manager (new architecture)
        if ($consumer instanceof \Bschmitt\Amqp\Core\Consumer) {
            $connectionManager = $consumer->getConnectionManager();
            if ($connectionManager !== null) {
                $connectionManager->disconnect();
                return;
            }
        }

        // Fallback: use Request::shutdown for backward compatibility
        if (method_exists($consumer, 'getChannel') && method_exists($consumer, 'getConnection')) {
            \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        }
    }
}
