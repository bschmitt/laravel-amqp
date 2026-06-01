<?php

namespace Bschmitt\Amqp\Queue;

use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Managers\ConnectionManager;
use Bschmitt\Amqp\Managers\ExchangeManager;
use Bschmitt\Amqp\Managers\QueueManager;
use Bschmitt\Amqp\Support\ConfigurationProvider;
use Bschmitt\Amqp\Support\QueueConfigResolver;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class AmqpQueue extends Queue implements QueueContract
{
    /**
     * The queue connection entry from config/queue.php.
     *
     * Kept separate from {@see Queue::$config} (added in Laravel 12) so we do
     * not redeclare the parent property, which fatals on PHP 8.4+.
     *
     * @var array
     */
    protected $queueConnectionConfig;

    /**
     * @var array
     */
    protected $amqpProperties;

    /**
     * @var PublisherFactoryInterface
     */
    protected $publisherFactory;

    /**
     * @var MessageFactory
     */
    protected $messageFactory;

    /**
     * @var ConnectionManager|null
     */
    protected $connectionManager;

    /**
     * @var string|null
     */
    protected $activeQueue;

    /**
     * @var AmqpJob|null
     */
    protected $currentJob;

    /**
     * @param \Illuminate\Container\Container $container
     * @param array $config
     * @param PublisherFactoryInterface $publisherFactory
     * @param MessageFactory $messageFactory
     */
    public function __construct(
        $container,
        array $config,
        PublisherFactoryInterface $publisherFactory,
        MessageFactory $messageFactory
    ) {
        $this->container = $container;
        $this->queueConnectionConfig = $config;
        $this->syncParentQueueConfig($config);
        $this->publisherFactory = $publisherFactory;
        $this->messageFactory = $messageFactory;
        $this->amqpProperties = QueueConfigResolver::resolve(
            $config,
            $container->make('config')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        return $this->queueConnectionConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function setConfig(array $config)
    {
        $this->queueConnectionConfig = $config;
        $this->syncParentQueueConfig($config);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function size($queue = null)
    {
        return $this->pendingSize($queue)
            + $this->delayedSize($queue)
            + $this->reservedSize($queue);
    }

    /**
     * {@inheritdoc}
     */
    public function pendingSize($queue = null)
    {
        return $this->passiveQueueMessageCount($this->getQueue($queue));
    }

    /**
     * {@inheritdoc}
     *
     * Delayed jobs use per-TTL queues ({@see laterRaw()}); without the
     * management API we cannot enumerate those queues here.
     */
    public function delayedSize($queue = null)
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     *
     * Unacknowledged (reserved) messages are not exposed via passive declare.
     */
    public function reservedSize($queue = null)
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function creationTimeOfOldestPendingJob($queue = null)
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            function ($payload, $queue) {
                return $this->pushRaw($payload, $queue);
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $queue = $this->getQueue($queue);
        $attempts = $options['attempts'] ?? 0;

        $properties = $this->propertiesForQueue($queue);
        $routingKey = $this->routingKeyForQueue($queue);

        $message = $this->createAmqpMessage($payload, $attempts);

        $publisher = $this->publisherFactory->create($properties);

        try {
            $publisher->publish($routingKey, $message);

            return $this->extractPayloadId($payload);
        } finally {
            $this->disconnectPublisher($publisher);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            function ($payload, $queue, $delay) {
                return $this->laterRaw($delay, $payload, $queue);
            }
        );
    }

    /**
     * @param int $delay Seconds until the job should run
     * @param string $payload
     * @param string|null $queue
     * @param int $attempts
     * @return string|null
     */
    public function laterRaw($delay, $payload, $queue = null, $attempts = 0)
    {
        $queue = $this->getQueue($queue);
        $ttl = $this->secondsUntil($delay) * 1000;

        if ($ttl <= 0) {
            return $this->pushRaw($payload, $queue, ['attempts' => $attempts]);
        }

        $delayQueue = $queue.'.delay.'.$ttl;
        $properties = $this->propertiesForQueue($queue);
        $properties['queue'] = $delayQueue;
        $properties['routing'] = [$delayQueue];
        $properties['queue_properties'] = array_merge(
            (array) ($properties['queue_properties'] ?? []),
            [
                'x-dead-letter-exchange' => $properties['exchange'] ?? '',
                'x-dead-letter-routing-key' => $this->routingKeyForQueue($queue),
                'x-message-ttl' => $ttl,
                'x-expires' => $ttl * 2,
            ]
        );

        $message = $this->createAmqpMessage($payload, $attempts);
        $publisher = $this->publisherFactory->create($properties);

        try {
            $publisher->publish($delayQueue, $message);

            return $this->extractPayloadId($payload);
        } finally {
            $this->disconnectPublisher($publisher);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        try {
            $message = $this->getConnectionManager($queue)->getChannel()->basic_get($queue);

            if ($message instanceof AMQPMessage) {
                return $this->currentJob = new AmqpJob(
                    $this->container,
                    $this,
                    $message,
                    $this->connectionName,
                    $queue
                );
            }
        } catch (AMQPProtocolChannelException $exception) {
            if (isset($exception->amqp_reply_code) && $exception->amqp_reply_code === 404) {
                $this->resetConnectionManager();

                return null;
            }

            throw $exception;
        }

        return null;
    }

    /**
     * @param AmqpJob $job
     * @return void
     */
    public function ack(AmqpJob $job): void
    {
        $job->getAmqpMessage()->getChannel()->basic_ack(
            $job->getAmqpMessage()->getDeliveryTag()
        );
    }

    /**
     * @param AmqpJob $job
     * @param bool $requeue
     * @return void
     */
    public function reject(AmqpJob $job, $requeue = false): void
    {
        $job->getAmqpMessage()->getChannel()->basic_reject(
            $job->getAmqpMessage()->getDeliveryTag(),
            $requeue
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getQueue($queue = null)
    {
        return $queue ?: ($this->queueConnectionConfig['queue'] ?? $this->amqpProperties['queue'] ?? 'default');
    }

    /**
     * @return array
     */
    public function getAmqpProperties(): array
    {
        return $this->amqpProperties;
    }

    /**
     * @param string $queue
     * @return array
     */
    protected function propertiesForQueue(string $queue): array
    {
        $properties = $this->amqpProperties;
        $properties['queue'] = $queue;
        $properties['routing'] = [$this->routingKeyForQueue($queue)];
        $properties['queue_force_declare'] = true;

        return $properties;
    }

    /**
     * @param string $queue
     * @return string
     */
    protected function routingKeyForQueue(string $queue): string
    {
        $routing = $this->amqpProperties['routing'] ?? [$queue];
        $routing = (array) $routing;

        return (string) ($routing[0] ?? $queue);
    }

    /**
     * @param string $payload
     * @param int $attempts
     * @return \Bschmitt\Amqp\Models\Message
     */
    protected function createAmqpMessage($payload, $attempts = 0)
    {
        return $this->messageFactory->create($payload, [
            'laravel' => [
                'attempts' => $attempts,
            ],
        ], [
            'content_type' => 'application/json',
            'delivery_mode' => 2,
        ]);
    }

    /**
     * @param string|null $queue
     * @return ConnectionManager
     */
    protected function getConnectionManager($queue = null): ConnectionManager
    {
        $queue = $this->getQueue($queue);

        if ($this->connectionManager !== null && $this->activeQueue === $queue) {
            return $this->connectionManager;
        }

        if ($this->connectionManager !== null) {
            $this->connectionManager->disconnect();
        }

        $properties = $this->propertiesForQueue($queue);
        $config = new ConfigurationProvider(QueueConfigResolver::toConfigRepository($properties));
        $this->connectionManager = new ConnectionManager($config);
        $this->connectionManager->connect();

        $exchangeManager = new ExchangeManager($config, $this->connectionManager);
        $exchangeManager->declareExchange();

        $queueManager = new QueueManager($config, $this->connectionManager);
        $queueManager->declareAndBind();

        $this->activeQueue = $queue;

        return $this->connectionManager;
    }

    /**
     * @return void
     */
    protected function resetConnectionManager(): void
    {
        if ($this->connectionManager !== null) {
            try {
                $this->connectionManager->disconnect();
            } catch (\Exception $e) {
                // Ignore cleanup errors when resetting after 404
            }
        }

        $this->connectionManager = null;
        $this->activeQueue = null;
    }

    /**
     * @param \Bschmitt\Amqp\Contracts\PublisherInterface $publisher
     * @return void
     */
    protected function disconnectPublisher($publisher): void
    {
        if ($publisher instanceof \Bschmitt\Amqp\Core\Publisher) {
            $manager = $publisher->getConnectionManager();
            if ($manager !== null) {
                $manager->disconnect();
            }
        }
    }

    /**
     * @param string|object $job
     * @param string $queue
     * @param mixed $data
     * @return array
     */
    protected function createPayloadArray($job, $queue, $data = '')
    {
        return array_merge(parent::createPayloadArray($job, $queue, $data), [
            'id' => (string) Str::uuid(),
        ]);
    }

    /**
     * @param string $payload
     * @return string|null
     */
    protected function extractPayloadId($payload)
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? ($decoded['id'] ?? null) : null;
    }

    /**
     * Passive queue declare message count (ready messages).
     *
     * @param string $queue
     * @return int
     */
    protected function passiveQueueMessageCount(string $queue): int
    {
        try {
            $channel = $this->getConnectionManager($queue)->getChannel();
            [, $size] = $channel->queue_declare($queue, true);

            return (int) $size;
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->getCode() === 404 || (isset($exception->amqp_reply_code) && $exception->amqp_reply_code === 404)) {
                return 0;
            }

            throw $exception;
        }
    }

    /**
     * Mirror config into {@see Queue::$config} on Laravel 12+.
     *
     * @param array $config
     * @return void
     */
    protected function syncParentQueueConfig(array $config): void
    {
        if (!property_exists(parent::class, 'config')) {
            return;
        }

        $this->config = $config;
    }
}
