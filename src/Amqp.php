<?php

namespace Bschmitt\Amqp\Core;

use Closure;
use Bschmitt\Amqp\Contracts\PublisherInterface;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Managers\ConnectionManager;
use Bschmitt\Amqp\Models\Message;
use Illuminate\Support\Facades\App;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Amqp
{
    /**
     * @var PublisherInterface
     */
    protected $publisher;

    /**
     * @var ConsumerInterface
     */
    protected $consumer;

    /**
     * @var MessageFactory
     */
    protected $messageFactory;

    /**
     * @var array
     */
    protected static $batchMessages = [];

    /**
     * @param PublisherInterface|null $publisher
     * @param ConsumerInterface|null $consumer
     * @param MessageFactory|null $messageFactory
     */
    public function __construct(
        PublisherInterface $publisher = null,
        ConsumerInterface $consumer = null,
        MessageFactory $messageFactory = null
    ) {
        $this->publisher = $publisher ?? App::make(\Bschmitt\Amqp\Core\Publisher::class);
        $this->consumer = $consumer ?? App::make(\Bschmitt\Amqp\Core\Consumer::class);
        $this->messageFactory = $messageFactory ?? new MessageFactory();
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
        if (!($this->publisher instanceof \Bschmitt\Amqp\Core\Publisher)) {
            throw new \RuntimeException('Publisher must be an instance of Publisher class for property merging.');
        }

        $properties['routing'] = $routing;
        $publisher = $this->createPublisherInstance($properties);

        try {
            $applicationHeaders = $properties['application_headers'] ?? [];
            
            // Extract message-specific properties
            $messageProperties = [];
            $messagePropertyKeys = [
                'priority', 'correlation_id', 'reply_to', 'message_id', 
                'timestamp', 'type', 'user_id', 'app_id', 'expiration',
                'content_type', 'content_encoding', 'delivery_mode'
            ];
            
            foreach ($messagePropertyKeys as $key) {
                if (isset($properties[$key])) {
                    $messageProperties[$key] = $properties[$key];
                }
            }
            
            // Include application_headers in message properties if provided separately
            if (!empty($applicationHeaders)) {
                $messageProperties['application_headers'] = $applicationHeaders;
            }
            
            $message = $this->messageFactory->create($message, $applicationHeaders, $messageProperties);
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
        self::$batchMessages[] = [
            'routing' => $routing,
            'message' => $message,
        ];
    }

    /**
     * @param array $properties
     * @return void
     */
    public function batchPublish(array $properties = []): void
    {
        if (empty(self::$batchMessages)) {
            return;
        }

        if (!($this->publisher instanceof \Bschmitt\Amqp\Core\Publisher)) {
            throw new \RuntimeException('Publisher must be an instance of Publisher class for property merging.');
        }

        $publisher = $this->createPublisherInstance($properties);

        try {
            foreach (self::$batchMessages as $messageData) {
                if (!isset($messageData['routing']) || !isset($messageData['message'])) {
                    continue;
                }

                $message = $this->messageFactory->create($messageData['message']);
                $publisher->batchBasicPublish($messageData['routing'], $message);
            }

            $publisher->batchPublish();
            $this->forgetBatchedMessages();
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
        if (!($this->consumer instanceof \Bschmitt\Amqp\Core\Consumer)) {
            throw new \RuntimeException('Consumer must be an instance of Consumer class for property merging.');
        }

        $properties['queue'] = $queue;
        $consumer = $this->createConsumerInstance($properties);

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
     * Create a new publisher instance with merged properties
     *
     * @param array $properties
     * @return Publisher
     */
    protected function createPublisherInstance(array $properties): \Bschmitt\Amqp\Core\Publisher
    {
        $publisher = new \Bschmitt\Amqp\Core\Publisher();
        $publisher->mergeProperties($properties)->setup();
        return $publisher;
    }

    /**
     * Create a new consumer instance with merged properties
     *
     * @param array $properties
     * @return \Bschmitt\Amqp\Core\Consumer
     */
    protected function createConsumerInstance(array $properties): \Bschmitt\Amqp\Core\Consumer
    {
        $consumer = new \Bschmitt\Amqp\Core\Consumer();
        $consumer->mergeProperties($properties)->setup();
        return $consumer;
    }

    /**
     * Disconnect publisher resources
     *
     * @param \Bschmitt\Amqp\Core\Publisher $publisher
     * @return void
     */
    protected function disconnectPublisher(\Bschmitt\Amqp\Core\Publisher $publisher): void
    {
        if (isset($publisher->connectionManager) && $publisher->connectionManager instanceof ConnectionManager) {
            $publisher->connectionManager->disconnect();
        } else {
            \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
        }
    }

    /**
     * Disconnect consumer resources
     *
     * @param \Bschmitt\Amqp\Core\Consumer $consumer
     * @return void
     */
    protected function disconnectConsumer(\Bschmitt\Amqp\Core\Consumer $consumer): void
    {
        if (isset($consumer->connectionManager) && $consumer->connectionManager instanceof ConnectionManager) {
            $consumer->connectionManager->disconnect();
        } else {
            \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        }
    }

    /**
     * Remove the messages sent as a batch
     *
     * @return void
     */
    protected function forgetBatchedMessages(): void
    {
        self::$batchMessages = [];
    }
}
