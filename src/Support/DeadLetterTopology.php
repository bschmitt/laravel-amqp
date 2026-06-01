<?php

namespace Bschmitt\Amqp\Support;

/**
 * Declarative description of a retry + dead-letter topology around a single
 * work queue.
 *
 * The topology consists of three logical pieces:
 *
 *   1. The work queue (the one consumers read from). It is configured with
 *      `x-dead-letter-exchange` so that a `basic_reject(requeue=false)`
 *      forwards the message to the DLQ.
 *
 *   2. The dead-letter queue (DLQ). A regular durable queue bound to the DLX
 *      with the DLQ routing key. Holds messages whose retries were exhausted
 *      or that were rejected directly.
 *
 *   3. Per-delay retry queues, named `{queue}.retry.{ttl_ms}`, with
 *      `x-message-ttl` set to that delay and an `x-dead-letter-exchange`
 *      pointing back to the work queue's exchange + routing key. Messages
 *      republished here are routed back to the work queue automatically when
 *      their TTL expires.
 *
 * This class is a pure builder — it produces property arrays consumable by the
 * rest of the package (`Amqp::publish`/`Amqp::consume`) and never opens a
 * connection itself. Topology declaration is performed by
 * {@see \Bschmitt\Amqp\Core\Amqp::declareRetryTopology()} which iterates over
 * the property bags produced here.
 */
class DeadLetterTopology
{
    /** @var string */
    protected $queue;

    /** @var RetryPolicy */
    protected $retryPolicy;

    /** @var string */
    protected $exchange = '';

    /** @var string */
    protected $exchangeType = 'topic';

    /** @var string|null */
    protected $routingKey;

    /** @var string */
    protected $dlqQueue;

    /** @var string|null */
    protected $dlqExchange;

    /** @var string|null */
    protected $dlqRoutingKey;

    /** @var array<string, mixed> */
    protected $queueProperties = [];

    /** @var array<string, mixed> */
    protected $baseProperties = [];

    /**
     * @param string           $queue Name of the work queue.
     * @param RetryPolicy|null $retryPolicy Retry policy; defaults to {@see RetryPolicy::none()}.
     */
    public function __construct(string $queue, ?RetryPolicy $retryPolicy = null)
    {
        if ($queue === '') {
            throw new \InvalidArgumentException('Work queue name must be non-empty');
        }
        $this->queue = $queue;
        $this->retryPolicy = $retryPolicy ?? RetryPolicy::none();
        $this->dlqQueue = $queue.'.dlq';
    }

    /**
     * Fluent factory.
     */
    public static function for(string $queue, ?RetryPolicy $retryPolicy = null): self
    {
        return new self($queue, $retryPolicy);
    }

    /**
     * Override exchange / exchange type used by the work queue and (by
     * default) the DLX.
     */
    public function on(string $exchange, string $exchangeType = 'topic'): self
    {
        $this->exchange = $exchange;
        $this->exchangeType = $exchangeType;
        return $this;
    }

    /**
     * Set the routing key used to bind the work queue to its exchange.
     * Defaults to the queue name when not provided.
     */
    public function withRoutingKey(string $routingKey): self
    {
        $this->routingKey = $routingKey;
        return $this;
    }

    public function withRetryPolicy(RetryPolicy $policy): self
    {
        $this->retryPolicy = $policy;
        return $this;
    }

    /**
     * Override the DLQ queue name (defaults to "{queue}.dlq").
     */
    public function withDlqQueue(string $dlqQueue): self
    {
        if ($dlqQueue === '') {
            throw new \InvalidArgumentException('DLQ queue name must be non-empty');
        }
        $this->dlqQueue = $dlqQueue;
        return $this;
    }

    /**
     * Override the DLX exchange (and optionally the DLQ routing key).
     *
     * When not set the topology re-uses the work queue's exchange and binds
     * the DLQ with the DLQ queue name as the routing key.
     */
    public function withDlqExchange(string $exchange, ?string $routingKey = null): self
    {
        $this->dlqExchange = $exchange;
        if ($routingKey !== null) {
            $this->dlqRoutingKey = $routingKey;
        }
        return $this;
    }

    public function withDlqRoutingKey(string $routingKey): self
    {
        $this->dlqRoutingKey = $routingKey;
        return $this;
    }

    /**
     * Extra `queue_properties` (e.g. `x-max-length`) merged into the work
     * queue declaration. The DLX-related keys always take precedence.
     *
     * @param array<string, mixed> $properties
     */
    public function withQueueProperties(array $properties): self
    {
        $this->queueProperties = array_merge($this->queueProperties, $properties);
        return $this;
    }

    /**
     * Base properties merged into every produced property bag (connection
     * details, vhost, etc.). The topology never sets `host`/`port` itself.
     *
     * @param array<string, mixed> $properties
     */
    public function withBaseProperties(array $properties): self
    {
        $this->baseProperties = array_merge($this->baseProperties, $properties);
        return $this;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getDlqQueue(): string
    {
        return $this->dlqQueue;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function getExchangeType(): string
    {
        return $this->exchangeType;
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey ?? $this->queue;
    }

    public function getDlqExchange(): string
    {
        return $this->dlqExchange ?? $this->exchange;
    }

    public function getDlqRoutingKey(): string
    {
        return $this->dlqRoutingKey ?? $this->dlqQueue;
    }

    public function getRetryPolicy(): RetryPolicy
    {
        return $this->retryPolicy;
    }

    /**
     * Conventional name of the per-delay retry queue.
     */
    public function getRetryQueueName(int $delayMs): string
    {
        return $this->queue.'.retry.'.$delayMs;
    }

    /**
     * Property bag suitable to pass to {@see \Bschmitt\Amqp\Core\Amqp::consume()}
     * or {@see \Bschmitt\Amqp\Core\Amqp::publish()} for the work queue.
     *
     * @return array<string, mixed>
     */
    public function toWorkProperties(): array
    {
        $queueProperties = array_merge($this->queueProperties, [
            'x-dead-letter-exchange' => $this->getDlqExchange(),
            'x-dead-letter-routing-key' => $this->getDlqRoutingKey(),
        ]);

        return array_merge($this->baseProperties, [
            'exchange' => $this->exchange,
            'exchange_type' => $this->exchangeType,
            'queue' => $this->queue,
            'routing' => [$this->getRoutingKey()],
            'queue_force_declare' => true,
            'queue_properties' => $queueProperties,
        ]);
    }

    /**
     * Property bag for the DLQ.
     *
     * @return array<string, mixed>
     */
    public function toDlqProperties(): array
    {
        return array_merge($this->baseProperties, [
            'exchange' => $this->getDlqExchange(),
            'exchange_type' => $this->exchangeType,
            'queue' => $this->dlqQueue,
            'routing' => [$this->getDlqRoutingKey()],
            'queue_force_declare' => true,
            'queue_properties' => [],
        ]);
    }

    /**
     * Property bag for the retry queue used for a given delay.
     *
     * @return array<string, mixed>
     */
    public function toRetryQueueProperties(int $delayMs): array
    {
        if ($delayMs < 0) {
            throw new \InvalidArgumentException('Retry delay must be >= 0 ms');
        }

        $retryQueue = $this->getRetryQueueName($delayMs);

        $queueProperties = [
            'x-dead-letter-exchange' => $this->exchange,
            'x-dead-letter-routing-key' => $this->getRoutingKey(),
            'x-message-ttl' => $delayMs,
            'x-expires' => max(60000, $delayMs * 2),
        ];

        return array_merge($this->baseProperties, [
            'exchange' => $this->exchange,
            'exchange_type' => $this->exchangeType,
            'queue' => $retryQueue,
            'routing' => [$retryQueue],
            'queue_force_declare' => true,
            'queue_properties' => $queueProperties,
        ]);
    }

    /**
     * Distinct delay values used by the configured retry policy. Useful when
     * pre-declaring retry queues so the consumer doesn't have to redeclare on
     * every failure.
     *
     * @return int[]
     */
    public function plannedRetryDelays(): array
    {
        $delays = [];
        $max = $this->retryPolicy->maxAttempts();
        for ($attempt = 1; $attempt <= $max; $attempt++) {
            $delay = $this->retryPolicy->delayFor($attempt);
            $delays[$delay] = $delay;
        }
        return array_values($delays);
    }
}
