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
     * Listen to multiple routing keys with auto-generated queue
     * 
     * This is a convenience method that automatically creates a queue and binds it
     * to multiple routing keys. It's equivalent to calling consume() with an
     * auto-generated queue name and multiple routing keys.
     * 
     * @param string|array $routingKeys Comma-separated string or array of routing keys
     * @param Closure $callback Message handler
     * @param array $properties Additional configuration
     * @return bool
     */
    public function listen($routingKeys, Closure $callback, array $properties = []): bool
    {
        // Convert comma-separated string to array
        if (is_string($routingKeys)) {
            $routingKeys = array_filter(array_map('trim', explode(',', $routingKeys)), function($key) {
                return !empty($key);
            });
        }
        
        if (!is_array($routingKeys) || empty($routingKeys)) {
            throw new \InvalidArgumentException('Routing keys must be a non-empty string or array');
        }
        
        // Generate queue name if not provided
        if (empty($properties['queue'])) {
            $properties['queue'] = 'listener-' . uniqid('', true);
        }
        
        // Set routing keys
        $properties['routing'] = $routingKeys;
        
        // Set defaults for auto-generated queues if not specified
        if (!isset($properties['queue_auto_delete'])) {
            $properties['queue_auto_delete'] = true;
        }
        
        // Ensure exchange is set (default to topic if not specified)
        if (empty($properties['exchange_type'])) {
            $properties['exchange_type'] = 'topic';
        }
        
        return $this->consume($properties['queue'], $callback, $properties);
    }

    /**
     * Make an RPC call and wait for response
     * 
     * This is a convenience method for RPC (Remote Procedure Call) patterns.
     * It publishes a request with a correlation_id and reply_to queue, then
     * waits for a response with the matching correlation_id.
     * 
     * @param string $routingKey Routing key for the request
     * @param mixed $request Request data
     * @param array $properties Additional configuration
     * @param int $timeout Timeout in seconds (default: 30)
     * @return mixed|null The response data, or null if timeout
     */
    public function rpc(string $routingKey, $request, array $properties = [], int $timeout = 30)
    {
        // Generate unique correlation ID
        $correlationId = uniqid('rpc_', true);
        
        // Create a unique reply queue for this request
        $replyQueue = 'rpc-reply-' . $correlationId;
        
        // Set up response storage
        $responseReceived = false;
        $response = null;
        
        // Set up response consumer BEFORE publishing
        $consumerProperties = array_merge($properties, [
            'queue' => $replyQueue,
            'timeout' => $timeout,
            'queue_auto_delete' => true,
            'queue_exclusive' => true,
        ]);
        
        $consumer = $this->createConsumerInstance($consumerProperties);
        
        try {
            // Set up the consumer callback
            $channel = $consumer->getChannel();
            $channel->basic_consume(
                $replyQueue,
                '',
                false,
                false,
                false,
                false,
                function ($message) use (&$responseReceived, &$response, $correlationId) {
                    // Check if this is the response we're waiting for
                    if ($message->get('correlation_id') === $correlationId) {
                        $response = $message->body;
                        $responseReceived = true;
                        $message->getChannel()->basic_ack($message->getDeliveryTag());
                        // Cancel consumer to stop waiting
                        $message->getChannel()->basic_cancel($message->getConsumerTag());
                    } else {
                        // Not our response, requeue it
                        $message->getChannel()->basic_reject($message->getDeliveryTag(), true);
                    }
                }
            );
            
            // Publish request
            $requestProperties = array_merge($properties, [
                'correlation_id' => $correlationId,
                'reply_to' => $replyQueue,
            ]);
            
            $this->publish($routingKey, $request, $requestProperties);
            
            // Wait for response with timeout
            $startTime = time();
            while (!$responseReceived && (time() - $startTime) < $timeout) {
                $channel->wait(null, false, 1); // Wait 1 second at a time
            }
            
            return $responseReceived ? $response : null;
        } finally {
            $this->disconnectConsumer($consumer);
        }
    }

    /**
     * Get connection configuration for a specific connection name
     * 
     * This is a helper method that retrieves connection configuration from
     * the config file. Use it with publish/consume by passing the returned
     * config as the properties parameter.
     * 
     * @param string $connection Connection name from config
     * @return array Connection configuration
     */
    public function getConnectionConfig(string $connection): array
    {
        // Try to get config from App container if available (Laravel context)
        try {
            $config = \Illuminate\Support\Facades\App::make('config');
            $connectionConfig = $config->get("amqp.properties.{$connection}", []);
            
            if (empty($connectionConfig)) {
                throw new \InvalidArgumentException("Connection '{$connection}' not found in config");
            }
            
            return $connectionConfig;
        } catch (\Exception $e) {
            // If App facade is not available, try to load config directly
            $configFile = __DIR__ . '/../config/amqp.php';
            if (file_exists($configFile)) {
                $config = include $configFile;
                $connectionConfig = $config['properties'][$connection] ?? [];
                
                if (empty($connectionConfig)) {
                    throw new \InvalidArgumentException("Connection '{$connection}' not found in config");
                }
                
                return $connectionConfig;
            }
            
            throw new \RuntimeException("Cannot load connection '{$connection}': " . $e->getMessage());
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
