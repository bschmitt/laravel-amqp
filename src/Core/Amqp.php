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
