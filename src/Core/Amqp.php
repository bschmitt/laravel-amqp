<?php

namespace Bschmitt\Amqp\Core;

use Closure;
use Bschmitt\Amqp\Contracts\PublisherInterface;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Contracts\ConsumerFactoryInterface;
use Bschmitt\Amqp\Contracts\BatchManagerInterface;
use Bschmitt\Amqp\Contracts\MessageContractInterface;
use Bschmitt\Amqp\Contracts\MessageSerializerInterface;
use Bschmitt\Amqp\Contracts\TracePropagatorInterface;
use Bschmitt\Amqp\Exception\SchemaValidationException;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Managers\ConnectionManager;
use Bschmitt\Amqp\Managers\ConnectionPool;
use Bschmitt\Amqp\Managers\ResilientConnectionManager;
use Bschmitt\Amqp\Events\MessageFailed;
use Bschmitt\Amqp\Events\MessageHandled;
use Bschmitt\Amqp\Events\MessagePublished;
use Bschmitt\Amqp\Events\MessagePublishing;
use Bschmitt\Amqp\Events\MessageReceived;
use Bschmitt\Amqp\Models\Message;
use Bschmitt\Amqp\Rpc\RpcDispatcher;
use Bschmitt\Amqp\Support\AsyncPublisher;
use Bschmitt\Amqp\Support\ConsumePipeline;
use Bschmitt\Amqp\Support\ConsumerLifecycle;
use Bschmitt\Amqp\Support\CorrelationContext;
use Bschmitt\Amqp\Support\DeadLetterTopology;
use Bschmitt\Amqp\Support\DelayedPublisher;
use Bschmitt\Amqp\Support\EventDispatcher;
use Bschmitt\Amqp\Support\ExchangeTopology;
use Bschmitt\Amqp\Support\HighPerformanceWorker;
use Bschmitt\Amqp\Support\InteropEnvelope;
use Bschmitt\Amqp\Support\JsonMessageSerializer;
use Bschmitt\Amqp\Support\MessageHeaders;
use Bschmitt\Amqp\Support\MetricsCollector;
use Bschmitt\Amqp\Support\PublishBackoff;
use Bschmitt\Amqp\Support\QueueMetrics;
use Bschmitt\Amqp\Support\QueueProfile;
use Bschmitt\Amqp\Support\RetryHandler;
use Bschmitt\Amqp\Support\RetryPolicy;
use Bschmitt\Amqp\Support\RpcClient;
use Bschmitt\Amqp\Support\RpcServer;
use Bschmitt\Amqp\Support\Saga;
use Bschmitt\Amqp\Support\SchemaValidator;
use Bschmitt\Amqp\Support\W3cTracePropagator;
use Bschmitt\Amqp\Support\WorkerOptions;
use Bschmitt\Amqp\Testing\FakeAmqp;

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
     * @var MessageSerializerInterface|null
     */
    protected $serializer;

    /**
     * @var SchemaValidator|null
     */
    protected $schemaValidator;

    /**
     * @var TracePropagatorInterface|null
     */
    protected $tracePropagator;

    /**
     * @var MetricsCollector|null
     */
    protected $metricsCollector;

    /**
     * @var RpcDispatcher|null
     */
    protected $rpcDispatcher;

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
     * Override the default {@see MessageSerializerInterface} used by
     * {@see publishTyped()} / {@see consumeTyped()}.
     *
     * Pass `null` to fall back to {@see JsonMessageSerializer}.
     *
     * @param MessageSerializerInterface|null $serializer
     * @return $this
     */
    public function setSerializer(?MessageSerializerInterface $serializer): self
    {
        $this->serializer = $serializer;
        return $this;
    }

    /**
     * Get the active serializer, lazily defaulting to JSON.
     */
    public function getSerializer(): MessageSerializerInterface
    {
        if ($this->serializer === null) {
            $this->serializer = new JsonMessageSerializer();
        }
        return $this->serializer;
    }

    /**
     * Lazy accessor for the bundled JSON-Schema-lite validator.
     */
    public function schemaValidator(): SchemaValidator
    {
        if ($this->schemaValidator === null) {
            $this->schemaValidator = new SchemaValidator();
        }
        return $this->schemaValidator;
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

        $properties = $this->applyContextPropagation($properties);

        $this->events()->dispatch(new MessagePublishing($routing, $message, $properties));

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

            $result = $publisher->publish($routing, $message, $mandatory);

            $this->events()->dispatch(new MessagePublished($routing, $message, $properties, $result));
            $this->metrics()->incrementPublished($routing);

            return $result;
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
     * Declare the full retry + dead-letter topology described by the builder.
     *
     * Declares the work queue (with DLX pointing at the DLQ), the DLQ itself,
     * and one retry queue per distinct delay used by the policy. Idempotent —
     * RabbitMQ silently no-ops re-declares as long as arguments match the
     * existing definition.
     *
     * @param DeadLetterTopology $topology
     * @return void
     */
    public function declareRetryTopology(DeadLetterTopology $topology): void
    {
        $this->declareViaPublisher($topology->toWorkProperties());
        $this->declareViaPublisher($topology->toDlqProperties());

        foreach ($topology->plannedRetryDelays() as $delayMs) {
            $this->declareViaPublisher($topology->toRetryQueueProperties($delayMs));
        }
    }

    /**
     * Convenience: build a {@see RetryHandler} bound to this Amqp instance.
     *
     * @param callable           $handler  Inner handler ($message, $resolver).
     * @param DeadLetterTopology $topology Topology describing retry queues + DLQ.
     * @param callable|null      $logger   Optional logger: fn($level, $message, array $context): void
     * @return RetryHandler
     */
    public function retryHandler(callable $handler, DeadLetterTopology $topology, ?callable $logger = null): RetryHandler
    {
        return new RetryHandler($handler, $topology, $this->publisherFactory, $this->messageFactory, $logger);
    }

    /**
     * Consume from a topology with built-in retry + DLQ semantics.
     *
     * Equivalent to wrapping the handler with {@see retryHandler()} and calling
     * {@see consume()} with the topology's work properties. Re-throws nothing —
     * failures are routed through the retry queue or to the DLQ per the policy.
     *
     * @param DeadLetterTopology $topology
     * @param callable           $handler  Inner handler ($message, $resolver).
     * @param array              $properties Extra consumer properties (merged on top of topology's).
     * @param callable|null      $logger
     * @return bool
     */
    public function consumeWithRetry(
        DeadLetterTopology $topology,
        callable $handler,
        array $properties = [],
        ?callable $logger = null
    ): bool {
        $wrapped = $this->retryHandler($handler, $topology, $logger);
        $merged = array_merge($topology->toWorkProperties(), $properties);

        return $this->consume($topology->getQueue(), function ($message, $resolver) use ($wrapped) {
            $wrapped($message, $resolver);
        }, $merged);
    }

    /**
     * Build a {@see DeadLetterTopology} pre-bound to this Amqp instance.
     *
     * Pure shortcut: returns `DeadLetterTopology::for($queue, $policy)` so
     * users don't have to import the class manually.
     *
     * @param string           $queue
     * @param RetryPolicy|null $policy
     * @return DeadLetterTopology
     */
    public function topology(string $queue, ?RetryPolicy $policy = null): DeadLetterTopology
    {
        return DeadLetterTopology::for($queue, $policy);
    }

    /* =================================================================
     *  Events, middleware, sagas, async publishing, test helpers
     * =================================================================
     */

    /**
     * Package event dispatcher (falls back to a local-only singleton outside Laravel).
     *
     * @return EventDispatcher
     */
    public function events(): EventDispatcher
    {
        return EventDispatcher::instance();
    }

    /**
     * Build an asynchronous publisher bound to this Amqp's factories.
     *
     * @param array<string, mixed> $properties Base properties (exchange, queue, ...).
     * @return AsyncPublisher
     */
    public function asyncPublisher(array $properties = []): AsyncPublisher
    {
        return new AsyncPublisher($this->publisherFactory, $this->messageFactory, $properties);
    }

    /**
     * Consume with a middleware pipeline. Each middleware receives
     * `(AMQPMessage $message, callable $next)` and must call `$next($message)`
     * to invoke the handler (or short-circuit to skip it).
     *
     * @param string                                                          $queue
     * @param Closure                                                         $handler `fn($message, $resolver): void`
     * @param array<int, callable|\Bschmitt\Amqp\Contracts\ConsumeMiddlewareInterface> $middlewares
     * @param array<string, mixed>                                            $properties
     * @return bool
     */
    public function consumeWithMiddleware(string $queue, Closure $handler, array $middlewares, array $properties = []): bool
    {
        $pipeline = (new ConsumePipeline())->pushMany($middlewares);
        $wrapped = $pipeline->wrap(function ($message, $resolver) use ($handler, $queue) {
            $this->metrics()->incrementConsumed($queue);
            $this->events()->dispatch(new MessageReceived($queue, $message));
            $start = microtime(true);
            try {
                $result = $handler($message, $resolver);
                $this->metrics()->incrementHandled();
                $this->events()->dispatch(new MessageHandled(
                    $queue,
                    $message,
                    (microtime(true) - $start) * 1000.0
                ));

                return $result;
            } catch (\Throwable $e) {
                $this->metrics()->incrementFailed($queue);
                $this->events()->dispatch(new MessageFailed($queue, $message, $e));
                throw $e;
            }
        });

        return $this->consume($queue, $wrapped, $properties);
    }

    /**
     * Build a {@see Saga} workflow.
     *
     * @param string $name
     * @return Saga
     */
    public function saga(string $name = 'saga'): Saga
    {
        return new Saga($name);
    }

    /**
     * Swap the Laravel-bound Amqp singleton for a {@see FakeAmqp} that records
     * publishes instead of touching the broker. Returns the fake so tests can
     * register assertions.
     *
     * @return FakeAmqp
     */
    public static function fake(): FakeAmqp
    {
        $fake = new FakeAmqp();

        try {
            $app = \Illuminate\Support\Facades\App::getFacadeApplication();
            if ($app !== null) {
                $app->instance('Amqp', $fake);
                $app->instance(self::class, $fake);
            }
        } catch (\Throwable $e) {
            // Outside Laravel — caller can still use the returned instance directly.
        }

        return $fake;
    }

    /**
     * In-process publish/consume counters for observability.
     *
     * @return MetricsCollector
     */
    public function metrics(): MetricsCollector
    {
        if ($this->metricsCollector === null) {
            $this->metricsCollector = new MetricsCollector();
        }

        return $this->metricsCollector;
    }

    /**
     * Build an {@see RpcClient} with optional default properties.
     *
     * @param array<string, mixed> $properties
     * @return RpcClient
     */
    public function rpcClient(array $properties = []): RpcClient
    {
        return new RpcClient($this, $properties);
    }

    /**
     * Build an {@see RpcServer} for request/response consumers.
     *
     * @return RpcServer
     */
    public function rpcServer(): RpcServer
    {
        return new RpcServer($this);
    }

    /**
     * gRPC-lite dispatcher (typed `Rpc::call()` / `Rpc::serve()`).
     *
     * Distinct from the lower-level {@see rpc()} method, which performs a
     * single synchronous request/reply with a raw payload.
     *
     * @return RpcDispatcher
     */
    public function rpcDispatcher(): RpcDispatcher
    {
        if ($this->rpcDispatcher === null) {
            $this->rpcDispatcher = new RpcDispatcher($this);
        }

        return $this->rpcDispatcher;
    }

    /**
     * Override the active gRPC-lite dispatcher (primarily for tests).
     *
     * @param RpcDispatcher|null $dispatcher
     * @return $this
     */
    public function setRpcDispatcher(?RpcDispatcher $dispatcher): self
    {
        $this->rpcDispatcher = $dispatcher;

        return $this;
    }

    /**
     * Publish with cross-service interop headers for polyglot consumers.
     *
     * @param string               $routing
     * @param mixed                $message
     * @param string               $messageType   Logical type (e.g. orders.created).
     * @param string               $sourceService Publishing service name.
     * @param array<string, mixed> $properties
     * @param string               $schemaVersion
     * @return bool|null
     */
    public function publishInterop(
        string $routing,
        $message,
        string $messageType,
        string $sourceService,
        array $properties = [],
        string $schemaVersion = '1.0'
    ) {
        $contentType = (string) ($properties['content_type'] ?? 'application/json');
        $properties = InteropEnvelope::applyToPublishProperties(
            $properties,
            $messageType,
            $sourceService,
            $schemaVersion,
            $contentType
        );

        if ($contentType === 'application/json' && !is_string($message)) {
            $message = json_encode($message);
        }

        return $this->publish($routing, $message, $properties);
    }

    /**
     * Consume interop messages; handler receives {@see InteropMessage} as first arg.
     *
     * @param string   $queue
     * @param Closure  $callback `fn(InteropMessage $interop, AMQPMessage $raw, $resolver): void`
     * @param array<string, mixed> $properties
     * @return bool
     */
    public function consumeInterop(string $queue, Closure $callback, array $properties = []): bool
    {
        return $this->consume($queue, function ($message, $resolver) use ($callback) {
            $interop = InteropEnvelope::fromMessage($message);
            $callback($interop, $message, $resolver);
        }, $properties);
    }

    /**
     * Fetch normalized queue metrics from the Management API.
     *
     * @param string               $queue
     * @param string|null          $vhost
     * @param array<string, mixed> $properties
     * @return QueueMetrics
     */
    public function queueMetrics(string $queue, ?string $vhost = null, array $properties = []): QueueMetrics
    {
        $data = $this->getQueueStatistics($queue, $vhost, $properties);

        return QueueMetrics::fromManagementApi($data);
    }

    /**
     * Alias for {@see getQueueStatistics()} returning a single queue.
     *
     * @param string               $queue
     * @param string|null          $vhost
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    public function getQueueStats(string $queue, ?string $vhost = null, array $properties = []): array
    {
        return $this->getQueueStatistics($queue, $vhost, $properties);
    }

    /**
     * @return WorkerOptions
     */
    public function workerOptions(): WorkerOptions
    {
        return WorkerOptions::throughput();
    }

    /**
     * @param WorkerOptions|null $options
     * @return HighPerformanceWorker
     */
    public function highPerformanceWorker(?WorkerOptions $options = null): HighPerformanceWorker
    {
        return new HighPerformanceWorker($this, $options);
    }

    /**
     * Consume with throughput-oriented QoS defaults.
     *
     * @param string               $queue
     * @param Closure              $handler
     * @param array<string, mixed> $properties
     * @param WorkerOptions|null   $options
     * @return bool
     */
    public function consumeOptimized(
        string $queue,
        Closure $handler,
        array $properties = [],
        ?WorkerOptions $options = null
    ): bool {
        return $this->highPerformanceWorker($options)->run($queue, $handler, $properties);
    }

    /**
     * Declare an exchange and all bound queues from a topology builder.
     *
     * @param ExchangeTopology $topology
     * @return void
     */
    public function declareExchangeTopology(ExchangeTopology $topology): void
    {
        foreach ($topology->declarationSteps() as $step) {
            $this->declareViaPublisher($step);
        }
    }

    /**
     * Shortcut for {@see ExchangeTopology::exchange()}.
     *
     * @param string $exchange
     * @param string $exchangeType
     * @return ExchangeTopology
     */
    public function exchangeTopology(string $exchange, string $exchangeType = 'topic'): ExchangeTopology
    {
        return ExchangeTopology::exchange($exchange, $exchangeType);
    }

    /**
     * Shortcut for {@see QueueProfile} presets.
     *
     * @return QueueProfile
     */
    public function queueProfile(): QueueProfile
    {
        return QueueProfile::classic();
    }

    /**
     * Consume with lifecycle hooks and optional correlation/trace propagation.
     *
     * Pass `propagate_correlation` and/or `propagate_trace` in `$properties`
     * to inherit context from each incoming message before the handler runs.
     *
     * @param string                    $queue
     * @param Closure                   $callback
     * @param ConsumerLifecycle|null    $lifecycle
     * @param array<string, mixed>      $properties
     * @return bool
     */
    public function consumeWithLifecycle(
        string $queue,
        Closure $callback,
        ?ConsumerLifecycle $lifecycle = null,
        array $properties = []
    ): bool {
        $lifecycle = $lifecycle !== null ? $lifecycle : new ConsumerLifecycle();
        $lifecycle->registerSignalHandlers();
        $lifecycle->fireStarting();

        $propagateCorrelation = !empty($properties['propagate_correlation']);
        $propagateTrace = !empty($properties['propagate_trace']);
        $consumerProperties = $properties;
        unset($consumerProperties['propagate_correlation'], $consumerProperties['propagate_trace']);

        $wrapped = $lifecycle->wrap(function ($message, $resolver) use (
            $callback,
            $propagateCorrelation,
            $propagateTrace
        ) {
            if ($propagateCorrelation) {
                CorrelationContext::inheritFromMessage($message);
            }
            if ($propagateTrace) {
                $this->tracePropagator()->extract(MessageHeaders::toArray($message));
            }

            return $callback($message, $resolver);
        });

        try {
            return $this->consume($queue, $wrapped, $consumerProperties);
        } finally {
            $lifecycle->fireStopping();
        }
    }

    /**
     * Global connection pool for long-lived workers.
     *
     * @return ConnectionPool
     */
    public static function connectionPool(): ConnectionPool
    {
        return ConnectionPool::instance();
    }

    /**
     * Build a resilient connection manager from inline properties.
     *
     * @param array<string, mixed> $properties Merged AMQP connection properties.
     * @param array<string, mixed> $options    ResilientConnectionManager options.
     * @return ResilientConnectionManager
     */
    public function resilientConnection(array $properties = [], array $options = []): ResilientConnectionManager
    {
        $config = $this->buildConfigurationProviderFromProperties($properties);
        $inner = new ConnectionManager($config);

        return new ResilientConnectionManager($inner, $options);
    }

    /**
     * @param TracePropagatorInterface $propagator
     * @return $this
     */
    public function setTracePropagator(TracePropagatorInterface $propagator): self
    {
        $this->tracePropagator = $propagator;

        return $this;
    }

    /**
     * Active trace propagator (defaults to W3C).
     */
    public function tracePropagator(): TracePropagatorInterface
    {
        if ($this->tracePropagator === null) {
            $this->tracePropagator = new W3cTracePropagator();
        }

        return $this->tracePropagator;
    }

    /**
     * Merge correlation and trace headers when requested via property flags.
     *
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    protected function applyContextPropagation(array $properties): array
    {
        if (!empty($properties['propagate_correlation'])) {
            $properties = CorrelationContext::applyToPublishProperties($properties);
            unset($properties['propagate_correlation']);
        }

        if (!empty($properties['propagate_trace'])) {
            $headers = (array) ($properties['application_headers'] ?? []);
            $properties['application_headers'] = $this->tracePropagator()->inject($headers);
            unset($properties['propagate_trace']);
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $properties
     * @return \Bschmitt\Amqp\Contracts\ConfigurationProviderInterface
     */
    protected function buildConfigurationProviderFromProperties(array $properties): \Bschmitt\Amqp\Contracts\ConfigurationProviderInterface
    {
        try {
            $config = \Illuminate\Support\Facades\App::make(\Bschmitt\Amqp\Contracts\ConfigurationProviderInterface::class);
            if ($config instanceof \Bschmitt\Amqp\Support\ConfigurationProvider) {
                $config->mergeProperties($properties);

                return $config;
            }
        } catch (\Exception $e) {
            // Fall through.
        }

        $defaultConfig = include __DIR__ . '/../../config/amqp.php';
        $defaultProperties = $defaultConfig['properties'][$defaultConfig['use']] ?? [];
        $mergedProperties = array_merge($defaultProperties, $properties);

        $configArray = [
            'amqp' => [
                'use' => $defaultConfig['use'] ?? 'production',
                'properties' => [
                    $defaultConfig['use'] ?? 'production' => $mergedProperties,
                ],
            ],
        ];

        $configRepository = new \Illuminate\Config\Repository($configArray);

        return new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
    }

    /**
     * Declare a single queue/exchange via the publisher factory (which sets up
     * exchanges + queues during {@see Publisher::setup()} without actually
     * publishing anything).
     *
     * @param array<string, mixed> $properties
     * @return void
     */
    protected function declareViaPublisher(array $properties): void
    {
        $publisher = $this->publisherFactory->create($properties);
        try {
            // setup() is called inside PublisherFactory::create(), so the
            // declaration has already happened. We just close the channel.
        } finally {
            $this->disconnectPublisher($publisher);
        }
    }

    /* =================================================================
     *  Delayed messaging
     * =================================================================
     */

    /**
     * Publish a message that the broker should hold for `$delayMs` ms before
     * delivering.
     *
     * Defaults to the TTL+DLX strategy which works on stock RabbitMQ; pass
     * `delay_strategy => 'plugin'` in `$properties` to use the
     * `rabbitmq-delayed-message-exchange` plugin instead (the target
     * exchange must be declared as `x-delayed-message`).
     *
     * @param string                       $routing     Routing key of the destination.
     * @param string|Message               $message     Body or pre-built Message.
     * @param int                          $delayMs     Delay before delivery, in milliseconds.
     * @param array<string, mixed>         $properties  Standard publish properties.
     *                                                  Use `delay_strategy => 'ttl'|'plugin'` to select strategy.
     *
     * @return bool|null
     */
    public function publishLater(string $routing, $message, int $delayMs, array $properties = [])
    {
        $strategy = (string) ($properties['delay_strategy'] ?? DelayedPublisher::STRATEGY_TTL);
        unset($properties['delay_strategy']);

        // Honour "use" by merging the named connection's config, just like publish().
        if (isset($properties['use'])) {
            $connectionName = $properties['use'];
            unset($properties['use']);
            $properties = array_merge($this->getConnectionConfig($connectionName), $properties);
        }

        return $this->delayedPublisher()->publishLater($routing, $message, $delayMs, $properties, $strategy);
    }

    /**
     * Convenience accessor for a {@see DelayedPublisher} bound to this
     * instance's publisher factory.
     */
    public function delayedPublisher(): DelayedPublisher
    {
        return new DelayedPublisher($this->publisherFactory, $this->messageFactory);
    }

    /* =================================================================
     *  Typed messaging (contracts + serializer + optional schema)
     * =================================================================
     */

    /**
     * Publish a {@see MessageContractInterface} after serialising it through
     * the active {@see MessageSerializerInterface}.
     *
     * Behavioural details:
     *  - Routing key defaults to `$contract::routingKey()` (if implemented) or
     *    the routing key supplied in `$properties['routing']`.
     *  - Exchange defaults to `$contract::exchange()` (if implemented), then
     *    `$properties['exchange']`, then the connection default.
     *  - `content_type` defaults to the serializer's content type.
     *  - If the contract exposes a non-null `schema()`, the produced payload
     *    is validated against it; failures raise {@see SchemaValidationException}.
     *
     * @param MessageContractInterface     $contract
     * @param array<string, mixed>         $properties
     * @return bool|null
     */
    public function publishTyped(MessageContractInterface $contract, array $properties = [])
    {
        $payload = $contract->toPayload();

        $schema = $this->resolveContractSchema($contract);
        if ($schema !== null) {
            $errors = $this->schemaValidator()->validate($payload, $schema);
            if (!empty($errors)) {
                throw new SchemaValidationException($errors);
            }
        }

        $serializer = $this->getSerializer();
        $body = $serializer->serialize($payload);

        $routing = $this->resolveContractRoutingKey($contract, $properties);
        $properties = $this->applyContractDefaults($contract, $properties, $serializer);

        return $this->publish($routing, $body, $properties);
    }

    /**
     * Delayed counterpart to {@see publishTyped()}.
     *
     * @param MessageContractInterface     $contract
     * @param int                          $delayMs
     * @param array<string, mixed>         $properties
     * @return bool|null
     */
    public function publishTypedLater(MessageContractInterface $contract, int $delayMs, array $properties = [])
    {
        $payload = $contract->toPayload();

        $schema = $this->resolveContractSchema($contract);
        if ($schema !== null) {
            $errors = $this->schemaValidator()->validate($payload, $schema);
            if (!empty($errors)) {
                throw new SchemaValidationException($errors);
            }
        }

        $serializer = $this->getSerializer();
        $body = $serializer->serialize($payload);

        $routing = $this->resolveContractRoutingKey($contract, $properties);
        $properties = $this->applyContractDefaults($contract, $properties, $serializer);

        return $this->publishLater($routing, $body, $delayMs, $properties);
    }

    /**
     * Consume typed messages.
     *
     * The inner handler receives `($typed, $message, $resolver)` where
     * `$typed` is an instance of `$contractClass::fromPayload(decoded body)`.
     * The raw {@see \PhpAmqpLib\Message\AMQPMessage} and the consumer
     * resolver are still passed so handlers retain access to delivery
     * metadata, headers, and the ack/reject API.
     *
     * If `$contractClass::schema()` returns a non-null schema, the decoded
     * payload is validated before instantiation; failures raise
     * {@see SchemaValidationException} from inside the consumer callback so
     * they interact properly with {@see RetryHandler} if it is also active.
     *
     * @param string                       $queue
     * @param class-string<MessageContractInterface> $contractClass
     * @param Closure                      $callback   `fn($typed, $message, $resolver): void`
     * @param array<string, mixed>         $properties
     * @return bool
     */
    public function consumeTyped(string $queue, string $contractClass, Closure $callback, array $properties = []): bool
    {
        if (!is_subclass_of($contractClass, MessageContractInterface::class)
            && !($contractClass === MessageContractInterface::class)) {
            throw new \InvalidArgumentException(sprintf(
                'Contract class [%s] must implement %s',
                $contractClass,
                MessageContractInterface::class
            ));
        }

        $serializer = $this->getSerializer();
        $validator = $this->schemaValidator();
        $schema = $this->resolveClassSchema($contractClass);

        return $this->consume($queue, function ($message, $resolver) use (
            $contractClass, $callback, $serializer, $validator, $schema
        ) {
            $payload = $serializer->deserialize((string) $message->body);

            if ($schema !== null) {
                $errors = $validator->validate($payload, $schema);
                if (!empty($errors)) {
                    throw new SchemaValidationException($errors);
                }
            }

            $typed = $contractClass::fromPayload($payload);
            $callback($typed, $message, $resolver);
        }, $properties);
    }

    /* =================================================================
     *  Publisher-side backoff
     * =================================================================
     */

    /**
     * Build a {@see PublishBackoff} bound to the given retry policy.
     *
     * Example:
     * ```
     * $amqp->withPublishBackoff(RetryPolicy::exponential(3, 100))->run(function () use ($amqp) {
     *     return $amqp->publish('orders.created', json_encode($order));
     * });
     * ```
     */
    public function withPublishBackoff(
        RetryPolicy $policy,
        ?callable $shouldRetry = null,
        ?callable $logger = null
    ): PublishBackoff {
        return new PublishBackoff($policy, $shouldRetry, $logger);
    }

    /* =================================================================
     *  Internal helpers for typed messaging
     * =================================================================
     */

    /**
     * @param MessageContractInterface $contract
     * @return array<string, mixed>|null
     */
    protected function resolveContractSchema(MessageContractInterface $contract): ?array
    {
        return $this->resolveClassSchema(get_class($contract));
    }

    /**
     * @param string $class
     * @return array<string, mixed>|null
     */
    protected function resolveClassSchema(string $class): ?array
    {
        if (!method_exists($class, 'schema')) {
            return null;
        }
        $schema = $class::schema();
        return is_array($schema) ? $schema : null;
    }

    /**
     * @param MessageContractInterface $contract
     * @param array<string, mixed>     $properties
     */
    protected function resolveContractRoutingKey(MessageContractInterface $contract, array $properties): string
    {
        if (!empty($properties['routing'])) {
            $routing = $properties['routing'];
            return is_array($routing) ? (string) reset($routing) : (string) $routing;
        }
        $class = get_class($contract);
        if (method_exists($class, 'routingKey')) {
            $key = $class::routingKey();
            if (is_string($key) && $key !== '') {
                return $key;
            }
        }
        return '';
    }

    /**
     * Merge content-type + exchange defaults supplied by the contract class.
     *
     * @param MessageContractInterface     $contract
     * @param array<string, mixed>         $properties
     * @param MessageSerializerInterface   $serializer
     * @return array<string, mixed>
     */
    protected function applyContractDefaults(
        MessageContractInterface $contract,
        array $properties,
        MessageSerializerInterface $serializer
    ): array {
        if (!isset($properties['content_type'])) {
            $properties['content_type'] = $serializer->contentType();
        }

        $class = get_class($contract);
        if (empty($properties['exchange']) && method_exists($class, 'exchange')) {
            $exchange = $class::exchange();
            if (is_string($exchange) && $exchange !== '') {
                $properties['exchange'] = $exchange;
            }
        }

        return $properties;
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
