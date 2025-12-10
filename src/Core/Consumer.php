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
        if ($config === null) {
            $config = \Illuminate\Support\Facades\App::make('config');
        }

        if ($config === null || !($config instanceof \Illuminate\Contracts\Config\Repository)) {
            $config = \Illuminate\Support\Facades\App::make('config');
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
