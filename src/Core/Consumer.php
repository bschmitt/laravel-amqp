<?php

namespace Bschmitt\Amqp\Core;

use Closure;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\ConfigurationProviderInterface;
use Bschmitt\Amqp\Contracts\ConnectionManagerInterface;
use Bschmitt\Amqp\Managers\ExchangeManager;
use Bschmitt\Amqp\Managers\QueueManager;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Consumer extends Request implements ConsumerInterface
{
    /**
     * @var ConnectionManagerInterface
     */
    protected $connectionManager;

    /**
     * @var ExchangeManager
     */
    protected $exchangeManager;

    /**
     * @var QueueManager
     */
    protected $queueManager;

    /**
     * @var int
     */
    protected $messageCount = 0;

    /**
     * @param ConfigurationProviderInterface|null $config
     * @param ConnectionManagerInterface|null $connectionManager
     * @param ExchangeManager|null $exchangeManager
     * @param QueueManager|null $queueManager
     */
    public function __construct(
        $config = null,
        ConnectionManagerInterface $connectionManager = null,
        ExchangeManager $exchangeManager = null,
        QueueManager $queueManager = null
    ) {
        // If config is a ConfigurationProviderInterface, use it directly
        if ($config instanceof \Bschmitt\Amqp\Contracts\ConfigurationProviderInterface) {
            // Use it as-is, parent will handle it
        } elseif ($config === null) {
            try {
                $config = \Illuminate\Support\Facades\App::make('config');
            } catch (\Exception $e) {
                // App facade not available, will be handled by parent
            }
        } elseif (!($config instanceof \Illuminate\Contracts\Config\Repository)) {
            try {
                $config = \Illuminate\Support\Facades\App::make('config');
            } catch (\Exception $e) {
                // App facade not available, config will be null
            }
        }

        parent::__construct($config);

        // Only create managers if explicitly provided or if we're using the new structure
        // This allows backward compatibility with tests that mock the old structure
        if ($connectionManager !== null || $exchangeManager !== null || $queueManager !== null) {
            if ($connectionManager === null) {
                $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($this);
            }

            if ($exchangeManager === null) {
                $exchangeManager = new ExchangeManager($this, $connectionManager);
            }

            if ($queueManager === null) {
                $queueManager = new QueueManager($this, $connectionManager);
            }

            $this->connectionManager = $connectionManager;
            $this->exchangeManager = $exchangeManager;
            $this->queueManager = $queueManager;
        }
    }

    /**
     * @return void
     */
    public function setup(): void
    {
        if ($this->connectionManager !== null && $this->exchangeManager !== null && $this->queueManager !== null) {
            $this->connectionManager->connect();
            $this->exchangeManager->declareExchange();
            $this->queueManager->declareAndBind();
            $this->messageCount = $this->queueManager->getMessageCount();
        } else {
            // Backward compatibility: use old Request::setup() method
            parent::setup();
            $this->messageCount = parent::getQueueMessageCount();
        }
    }

    /**
     * @param string  $queue
     * @param Closure $closure
     * @return bool
     */
    public function consume(string $queue, Closure $closure): bool
    {
        try {
            $persistent = (bool) $this->getProperty('persistent', false);

            if (!$persistent && $this->messageCount === 0) {
                throw new \Bschmitt\Amqp\Exception\Stop();
            }

            $this->configureQos();

            $channel = $this->getChannel();
            $channel->basic_consume(
                $queue,
                $this->getProperty('consumer_tag', ''),
                (bool) $this->getProperty('consumer_no_local', false),
                (bool) $this->getProperty('consumer_no_ack', false),
                (bool) $this->getProperty('consumer_exclusive', false),
                (bool) $this->getProperty('consumer_nowait', false),
                function ($message) use ($closure) {
                    $closure($message, $this);
                },
                null,
                $this->getProperty('consumer_properties', [])
            );

            $timeout = max(0, (int) $this->getProperty('timeout', 0));

            while (count($channel->callbacks) > 0) {
                $channel->wait(null, false, $timeout);
            }
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            return true;
        } catch (AMQPTimeoutException $e) {
            return true;
        }

        return true;
    }

    /**
     * Configure Quality of Service
     *
     * @return void
     */
    protected function configureQos(): void
    {
        if ($this->getProperty('qos', false)) {
            // Use connectionManager if available, otherwise use parent's getChannel()
            $channel = $this->connectionManager !== null 
                ? $this->connectionManager->getChannel() 
                : $this->getChannel();
            
            $channel->basic_qos(
                (int) $this->getProperty('qos_prefetch_size', 0),
                (int) $this->getProperty('qos_prefetch_count', 1),
                (bool) $this->getProperty('qos_a_global', false)
            );
        }
    }

    /**
     * Dynamically adjust consumer prefetch settings at runtime
     *
     * This method allows you to change the prefetch count and size while
     * the consumer is running, without recreating the consumer instance.
     *
     * @param int $prefetchCount Number of unacknowledged messages to prefetch (0 = unlimited)
     * @param int $prefetchSize Prefetch size in bytes (0 = unlimited, rarely used)
     * @param bool $global Whether to apply to all consumers on the channel (false = per-consumer)
     * @return void
     * @throws \RuntimeException if channel is not available
     */
    public function setPrefetch(int $prefetchCount, int $prefetchSize = 0, bool $global = false): void
    {
        if ($prefetchCount < 0) {
            throw new \InvalidArgumentException('Prefetch count must be non-negative');
        }

        if ($prefetchSize < 0) {
            throw new \InvalidArgumentException('Prefetch size must be non-negative');
        }

        // Use connectionManager if available, otherwise use parent's getChannel()
        try {
            $channel = $this->connectionManager !== null 
                ? $this->connectionManager->getChannel() 
                : $this->getChannel();
        } catch (\Exception $e) {
            throw new \RuntimeException('Channel is not available. Call setup() first.', 0, $e);
        }

        if ($channel === null) {
            throw new \RuntimeException('Channel is not available. Call setup() first.');
        }

        $channel->basic_qos($prefetchSize, $prefetchCount, $global);

        // Update internal properties to reflect the change
        // Note: This doesn't update the config repository, but updates the internal property cache
        // which is used by getProperty() and getPrefetch()
        $this->mergeProperties([
            'qos' => true,
            'qos_prefetch_count' => $prefetchCount,
            'qos_prefetch_size' => $prefetchSize,
            'qos_a_global' => $global,
        ]);
    }

    /**
     * Get current prefetch settings
     *
     * @return array{prefetch_count: int, prefetch_size: int, global: bool}
     */
    public function getPrefetch(): array
    {
        return [
            'prefetch_count' => (int) $this->getProperty('qos_prefetch_count', 1),
            'prefetch_size' => (int) $this->getProperty('qos_prefetch_size', 0),
            'global' => (bool) $this->getProperty('qos_a_global', false),
        ];
    }

    /**
     * @param AMQPMessage $message
     * @return void
     */
    public function acknowledge(AMQPMessage $message): void
    {
        $message->getChannel()->basic_ack($message->getDeliveryTag());

        $shutdownSignal = $this->getProperty('shutdown_signal', 'quit');
        if ($message->body === $shutdownSignal) {
            $message->getChannel()->basic_cancel($message->getConsumerTag());
        }
    }

    /**
     * @param AMQPMessage $message
     * @param bool $requeue
     * @return void
     */
    public function reject(AMQPMessage $message, bool $requeue = false): void
    {
        $message->getChannel()->basic_reject($message->getDeliveryTag(), $requeue);
    }

    /**
     * Reply to an RPC request
     * 
     * This is a convenience method for RPC patterns. It publishes a response message
     * to the reply_to queue specified in the original message, using the same
     * correlation_id to match the request and response.
     * 
     * @param AMQPMessage $requestMessage The original request message
     * @param mixed $response The response data to send back
     * @param array $properties Additional properties for the response message
     * @return bool|null
     */
    public function reply(AMQPMessage $requestMessage, $response, array $properties = []): ?bool
    {
        // Get properties from message
        $messageProperties = $requestMessage->get_properties();
        $replyTo = $messageProperties['reply_to'] ?? null;
        $correlationId = $messageProperties['correlation_id'] ?? null;
        
        if (empty($replyTo)) {
            throw new \RuntimeException('Cannot reply: original message has no reply_to property');
        }
        
        if (empty($correlationId)) {
            throw new \RuntimeException('Cannot reply: original message has no correlation_id property');
        }
        
        // Merge properties with correlation_id and reply_to
        // Use default exchange (empty string) to publish directly to queue
        // For default exchange, we need to connect but not declare exchange
        $responseProperties = array_merge($properties, [
            'correlation_id' => $correlationId,
            'exchange' => '',  // Default exchange - empty string
            'exchange_type' => 'direct',  // Not used for default exchange but required
            'routing' => [$replyTo],  // Queue name as routing key for default exchange
        ]);
        
        // Use the consumer's channel to publish the reply
        // The consumer's channel is more reliable than the message's channel
        // which might be closed after message processing
        $channel = $this->getChannel();
        
        // Verify channel is open
        if (!$channel->is_open()) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log('Reply failed: Consumer channel is not open');
                fwrite(STDERR, "Reply failed: Consumer channel is not open\n");
            }
            return false;
        }
        
        try {
            // Don't try to declare the queue - just publish directly
            // If the queue doesn't exist, the message will be silently dropped by RabbitMQ
            // This is acceptable for RPC patterns where the reply queue is created by the caller
            // The queue should already exist from the RPC caller's setup
            
            $messageFactory = new \Bschmitt\Amqp\Factories\MessageFactory();
            $message = $messageFactory->create($response, [
                'correlation_id' => $correlationId,
            ]);
            
            // Publish to default exchange (empty string) with queue name as routing key
            // Default exchange routes messages directly to queues by name
            // Note: If queue doesn't exist, message will be silently dropped
            // This is expected behavior for RPC - the caller creates the reply queue
            $channel->basic_publish($message, '', $replyTo, false);
            
            return true;
        } catch (\PhpAmqpLib\Exception\AMQPProtocolChannelException $e) {
            // Protocol errors - queue might not exist or channel issue
            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log('Reply failed (Protocol): ' . $e->getMessage());
                fwrite(STDERR, "Reply failed (Protocol): " . $e->getMessage() . "\n");
            }
            return false;
        } catch (\PhpAmqpLib\Exception\AMQPConnectionException $e) {
            // Connection errors
            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log('Reply failed (Connection): ' . $e->getMessage());
                fwrite(STDERR, "Reply failed (Connection): " . $e->getMessage() . "\n");
            }
            return false;
        } catch (\Exception $e) {
            // Other errors
            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log('Reply failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                error_log('Reply queue: ' . ($replyTo ?? 'null'));
                error_log('Channel state: ' . ($channel->is_open() ? 'open' : 'closed'));
                fwrite(STDERR, "Reply failed: " . $e->getMessage() . "\n");
            }
            return false;
        }
    }

    /**
     * @return void
     * @throws \Bschmitt\Amqp\Exception\Stop
     */
    public function stopWhenProcessed(): void
    {
        if (--$this->messageCount <= 0) {
            throw new \Bschmitt\Amqp\Exception\Stop();
        }
    }

    /**
     * @return \PhpAmqpLib\Channel\AMQPChannel
     */
    public function getChannel(): \PhpAmqpLib\Channel\AMQPChannel
    {
        if ($this->connectionManager !== null) {
            return $this->connectionManager->getChannel();
        }
        return parent::getChannel();
    }

    /**
     * @return \PhpAmqpLib\Connection\AMQPStreamConnection
     */
    public function getConnection(): \PhpAmqpLib\Connection\AMQPStreamConnection
    {
        if ($this->connectionManager !== null) {
            return $this->connectionManager->getConnection();
        }
        return parent::getConnection();
    }

    /**
     * @return int
     */
    public function getQueueMessageCount(): int
    {
        if ($this->queueManager !== null) {
            return $this->queueManager->getMessageCount();
        }
        return parent::getQueueMessageCount();
    }

    /**
     * Get connection manager (for resource cleanup)
     *
     * @return ConnectionManagerInterface|null
     */
    public function getConnectionManager(): ?ConnectionManagerInterface
    {
        return $this->connectionManager;
    }
}
