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
        // If 'use' is specified, merge that connection's config
        if (isset($properties['use'])) {
            $connectionName = $properties['use'];
            unset($properties['use']);
            $connectionConfig = $this->getConnectionConfig($connectionName);
            $properties = array_merge($connectionConfig, $properties);
        }
        
        $properties['routing'] = $routing;
        $publisher = $this->publisherFactory->create($properties);

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
        // If 'use' is specified, merge that connection's config
        if (isset($properties['use'])) {
            $connectionName = $properties['use'];
            unset($properties['use']);
            $connectionConfig = $this->getConnectionConfig($connectionName);
            $properties = array_merge($connectionConfig, $properties);
        }
        
        $properties['queue'] = $queue;
        $consumer = $this->consumerFactory->create($properties);

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
        
        // Start consumer in a way that allows timeout
        $consumer = $this->consumerFactory->create($consumerProperties);
        
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
                function ($message) use (&$responseReceived, &$response, $correlationId, $consumer) {
                    // Check if this is the response we're waiting for
                    // Get correlation_id from message properties
                    $messageProperties = $message->get_properties();
                    $msgCorrelationId = $messageProperties['correlation_id'] ?? null;
                    
                    if ($msgCorrelationId === $correlationId) {
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
                try {
                    $channel->wait(null, false, 1); // Wait 1 second at a time
                    if ($responseReceived) {
                        break;
                    }
                } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                    // Timeout is expected, continue loop to check if response was received
                    continue;
                } catch (\Exception $e) {
                    // Other exception, break
                    break;
                }
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
            $configFile = __DIR__ . '/../../config/amqp.php';
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
     * Unbind a queue from an exchange
     *
     * @param string $queue Queue name
     * @param string $exchange Exchange name
     * @param string $routingKey Routing key (optional, defaults to empty string)
     * @param array|null $arguments Additional arguments (optional)
     * @param array $properties Configuration properties (optional)
     * @return void
     */
    public function queueUnbind(string $queue, string $exchange, string $routingKey = '', ?array $arguments = null, array $properties = []): void
    {
        $management = $this->createManagementInstance($properties);
        try {
            $management->queueUnbind($queue, $exchange, $routingKey, $arguments);
        } finally {
            $this->disconnectManagement($management);
        }
    }

    /**
     * Unbind an exchange from another exchange
     *
     * @param string $destination Destination exchange name
     * @param string $source Source exchange name
     * @param string $routingKey Routing key (optional, defaults to empty string)
     * @param array|null $arguments Additional arguments (optional)
     * @param array $properties Configuration properties (optional)
     * @return void
     */
    public function exchangeUnbind(string $destination, string $source, string $routingKey = '', ?array $arguments = null, array $properties = []): void
    {
        $management = $this->createManagementInstance($properties);
        try {
            $management->exchangeUnbind($destination, $source, $routingKey, $arguments);
        } finally {
            $this->disconnectManagement($management);
        }
    }

    /**
     * Purge all messages from a queue
     *
     * @param string $queue Queue name
     * @param array $properties Configuration properties (optional)
     * @return int Number of messages purged
     */
    public function queuePurge(string $queue, array $properties = []): int
    {
        $management = $this->createManagementInstance($properties);
        try {
            return $management->queuePurge($queue);
        } finally {
            $this->disconnectManagement($management);
        }
    }

    /**
     * Delete a queue
     *
     * @param string $queue Queue name
     * @param bool $ifUnused Only delete if queue has no consumers (default: false)
     * @param bool $ifEmpty Only delete if queue is empty (default: false)
     * @param array $properties Configuration properties (optional)
     * @return int Number of messages deleted with the queue
     */
    public function queueDelete(string $queue, bool $ifUnused = false, bool $ifEmpty = false, array $properties = []): int
    {
        $management = $this->createManagementInstance($properties);
        try {
            return $management->queueDelete($queue, $ifUnused, $ifEmpty);
        } finally {
            $this->disconnectManagement($management);
        }
    }

    /**
     * Delete an exchange
     *
     * @param string $exchange Exchange name
     * @param bool $ifUnused Only delete if exchange is not in use (default: false)
     * @param array $properties Configuration properties (optional)
     * @return void
     */
    public function exchangeDelete(string $exchange, bool $ifUnused = false, array $properties = []): void
    {
        $management = $this->createManagementInstance($properties);
        try {
            $management->exchangeDelete($exchange, $ifUnused);
        } finally {
            $this->disconnectManagement($management);
        }
    }

    /**
     * Create a new management instance with merged properties
     *
     * @param array $properties
     * @return \Bschmitt\Amqp\Managers\Management
     */
    protected function createManagementInstance(array $properties): \Bschmitt\Amqp\Managers\Management
    {
        // Try to get config from App container if available (Laravel context)
        try {
            $config = \Illuminate\Support\Facades\App::make(\Bschmitt\Amqp\Contracts\ConfigurationProviderInterface::class);
            if ($config instanceof \Bschmitt\Amqp\Support\ConfigurationProvider) {
                $config->mergeProperties($properties);
            }
        } catch (\Exception $e) {
            // If App facade is not available (e.g., in tests), create config from properties
            $defaultConfig = include __DIR__ . '/../../config/amqp.php';
            $defaultProperties = $defaultConfig['properties'][$defaultConfig['use']] ?? [];
            $mergedProperties = array_merge($defaultProperties, $properties);
            
            $configArray = [
                'amqp' => [
                    'use' => $defaultConfig['use'] ?? 'production',
                    'properties' => [
                        $defaultConfig['use'] ?? 'production' => $mergedProperties
                    ]
                ]
            ];
            
            $configRepository = new \Illuminate\Config\Repository($configArray);
            $config = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        }
        
        $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($config);
        $connectionManager->connect();
        
        return new \Bschmitt\Amqp\Managers\Management($config, $connectionManager);
    }

    /**
     * Disconnect management resources
     *
     * @param \Bschmitt\Amqp\Managers\Management $management
     * @return void
     */
    protected function disconnectManagement(\Bschmitt\Amqp\Managers\Management $management): void
    {
        $connectionManager = $management->getConnectionManager();
        if ($connectionManager instanceof \Bschmitt\Amqp\Managers\ConnectionManager) {
            $connectionManager->disconnect();
        }
    }

    /**
     * Get queue statistics from Management API
     *
     * @param string|null $queueName Queue name (optional)
     * @param string|null $vhost Virtual host (optional)
     * @param array $properties Configuration properties (optional)
     * @return array
     */
    public function getQueueStatistics(?string $queueName = null, ?string $vhost = null, array $properties = []): array
    {
        $apiClient = $this->createManagementApiClient($properties);
        return $apiClient->getQueueStatistics($queueName, $vhost);
    }

    /**
     * Get connection information from Management API
     *
     * @param string|null $connectionName Connection name (optional)
     * @param array $properties Configuration properties (optional)
     * @return array
     */
    public function getConnections(?string $connectionName = null, array $properties = []): array
    {
        $apiClient = $this->createManagementApiClient($properties);
        return $apiClient->getConnections($connectionName);
    }

    /**
     * Get channel information from Management API
     *
     * @param string|null $channelName Channel name (optional)
     * @param array $properties Configuration properties (optional)
     * @return array
     */
    public function getChannels(?string $channelName = null, array $properties = []): array
    {
        $apiClient = $this->createManagementApiClient($properties);
        return $apiClient->getChannels($channelName);
    }

    /**
     * Get node information from Management API
     *
     * @param string|null $nodeName Node name (optional)
     * @param array $properties Configuration properties (optional)
     * @return array
     */
    public function getNodes(?string $nodeName = null, array $properties = []): array
    {
        $apiClient = $this->createManagementApiClient($properties);
        return $apiClient->getNodes($nodeName);
    }

    /**
     * List all policies
     *
     * @param string|null $vhost Virtual host (optional)
     * @param array $properties Configuration properties (optional)
     * @return array
     */
    public function listPolicies(?string $vhost = null, array $properties = []): array
    {
        $apiClient = $this->createManagementApiClient($properties);
        return $apiClient->listPolicies($vhost);
    }

    /**
     * Get a specific policy
     *
     * @param string $policyName Policy name
     * @param string|null $vhost Virtual host (optional)
     * @param array $properties Configuration properties (optional)
     * @return array
     */
    public function getPolicy(string $policyName, ?string $vhost = null, array $properties = []): array
    {
        $apiClient = $this->createManagementApiClient($properties);
        return $apiClient->getPolicy($policyName, $vhost);
    }

    /**
     * Create or update a policy
     *
     * @param string $policyName Policy name
     * @param array $definition Policy definition (must include 'pattern', optional: 'apply-to', 'definition', 'priority')
     * @param string|null $vhost Virtual host (optional)
     * @param array $properties Configuration properties (optional)
     * @return array
     */
    public function createPolicy(string $policyName, array $definition, ?string $vhost = null, array $properties = []): array
    {
        $apiClient = $this->createManagementApiClient($properties);
        return $apiClient->createPolicy($policyName, $definition, $vhost);
    }

    /**
     * Delete a policy
     *
     * @param string $policyName Policy name
     * @param string|null $vhost Virtual host (optional)
     * @param array $properties Configuration properties (optional)
     * @return void
     */
    public function deletePolicy(string $policyName, ?string $vhost = null, array $properties = []): void
    {
        $apiClient = $this->createManagementApiClient($properties);
        $apiClient->deletePolicy($policyName, $vhost);
    }

    /**
     * List all feature flags
     *
     * @param array $properties Configuration properties (optional)
     * @return array
     */
    public function listFeatureFlags(array $properties = []): array
    {
        $apiClient = $this->createManagementApiClient($properties);
        return $apiClient->listFeatureFlags();
    }

    /**
     * Get a specific feature flag
     *
     * @param string $featureFlagName Feature flag name
     * @param array $properties Configuration properties (optional)
     * @return array
     */
    public function getFeatureFlag(string $featureFlagName, array $properties = []): array
    {
        $apiClient = $this->createManagementApiClient($properties);
        return $apiClient->getFeatureFlag($featureFlagName);
    }

    /**
     * Create a new Management API client instance with merged properties
     *
     * @param array $properties
     * @return \Bschmitt\Amqp\Managers\ManagementApiClient
     */
    protected function createManagementApiClient(array $properties): \Bschmitt\Amqp\Managers\ManagementApiClient
    {
        // Try to get config from App container if available (Laravel context)
        try {
            $config = \Illuminate\Support\Facades\App::make(\Bschmitt\Amqp\Contracts\ConfigurationProviderInterface::class);
            if ($config instanceof \Bschmitt\Amqp\Support\ConfigurationProvider) {
                $config->mergeProperties($properties);
            }
        } catch (\Exception $e) {
            // If App facade is not available (e.g., in tests), create config from properties
            $defaultConfig = include __DIR__ . '/../../config/amqp.php';
            $defaultProperties = $defaultConfig['properties'][$defaultConfig['use']] ?? [];
            $mergedProperties = array_merge($defaultProperties, $properties);
            
            $configArray = [
                'amqp' => [
                    'use' => $defaultConfig['use'] ?? 'production',
                    'properties' => [
                        $defaultConfig['use'] ?? 'production' => $mergedProperties
                    ]
                ]
            ];
            
            $configRepository = new \Illuminate\Config\Repository($configArray);
            $config = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        }
        
        return new \Bschmitt\Amqp\Managers\ManagementApiClient($config);
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
