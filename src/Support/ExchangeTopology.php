<?php

namespace Bschmitt\Amqp\Support;

/**
 * Declarative builder for exchange + queue + binding declarations.
 *
 * Produces one property bag per bound queue, suitable for
 * {@see \Bschmitt\Amqp\Core\Amqp::declareExchangeTopology()}. Each bag
 * contains everything the publisher factory needs to declare the exchange,
 * declare the queue, and bind with the chosen routing key.
 */
class ExchangeTopology
{
    /** @var string */
    protected $exchange;

    /** @var string */
    protected $exchangeType;

    /** @var bool */
    protected $exchangeDurable = true;

    /** @var bool */
    protected $exchangeAutoDelete = false;

    /**
     * @var array<int, array{name: string, routing: string, profile: QueueProfile|null, properties: array<string, mixed>}>
     */
    protected $bindings = [];

    /** @var array<string, mixed> */
    protected $baseProperties = [];

    /**
     * @param string $exchange
     * @param string $exchangeType
     */
    public function __construct(string $exchange, string $exchangeType = 'topic')
    {
        if ($exchange === '') {
            throw new \InvalidArgumentException('Exchange name must be non-empty');
        }
        $this->exchange = $exchange;
        $this->exchangeType = $exchangeType;
    }

    /**
     * @param string $exchange
     * @param string $exchangeType
     * @return self
     */
    public static function exchange(string $exchange, string $exchangeType = 'topic'): self
    {
        return new self($exchange, $exchangeType);
    }

    /**
     * @param bool $durable
     * @return $this
     */
    public function durable(bool $durable = true): self
    {
        $this->exchangeDurable = $durable;

        return $this;
    }

    /**
     * @param bool $autoDelete
     * @return $this
     */
    public function autoDelete(bool $autoDelete = true): self
    {
        $this->exchangeAutoDelete = $autoDelete;

        return $this;
    }

    /**
     * Merge default connection/exchange flags into every declaration step.
     *
     * @param array<string, mixed> $properties
     * @return $this
     */
    public function withBaseProperties(array $properties): self
    {
        $this->baseProperties = array_merge($this->baseProperties, $properties);

        return $this;
    }

    /**
     * Bind a queue to this exchange.
     *
     * @param string            $queue
     * @param string|null       $routingKey Defaults to queue name.
     * @param QueueProfile|null $profile
     * @return $this
     */
    public function bindQueue(string $queue, ?string $routingKey = null, ?QueueProfile $profile = null): self
    {
        if ($queue === '') {
            throw new \InvalidArgumentException('Queue name must be non-empty');
        }

        $this->bindings[] = [
            'name' => $queue,
            'routing' => $routingKey !== null && $routingKey !== '' ? $routingKey : $queue,
            'profile' => $profile,
            'properties' => [],
        ];

        return $this;
    }

    /**
     * @return string
     */
    public function getExchange(): string
    {
        return $this->exchange;
    }

    /**
     * @return string
     */
    public function getExchangeType(): string
    {
        return $this->exchangeType;
    }

    /**
     * Names of all queues in this topology.
     *
     * @return string[]
     */
    public function queueNames(): array
    {
        $names = [];
        foreach ($this->bindings as $binding) {
            $names[] = $binding['name'];
        }

        return $names;
    }

    /**
     * Property bags for each queue binding (one declare call per entry).
     *
     * @return array<int, array<string, mixed>>
     */
    public function declarationSteps(): array
    {
        if ($this->bindings === []) {
            throw new \InvalidArgumentException('ExchangeTopology requires at least one bindQueue() call');
        }

        $steps = [];
        foreach ($this->bindings as $binding) {
            $props = array_merge($this->baseProperties, [
                'exchange' => $this->exchange,
                'exchange_type' => $this->exchangeType,
                'exchange_durable' => $this->exchangeDurable,
                'exchange_auto_delete' => $this->exchangeAutoDelete,
                'queue' => $binding['name'],
                'routing' => $binding['routing'],
                'queue_force_declare' => true,
            ]);

            if ($binding['profile'] instanceof QueueProfile) {
                $props = $binding['profile']->mergeInto($props);
            }

            if (!empty($binding['properties'])) {
                $props = array_merge($props, $binding['properties']);
            }

            $steps[] = $props;
        }

        return $steps;
    }

    /**
     * Properties for publishing/consuming on a specific queue in this topology.
     *
     * @param string $queue
     * @return array<string, mixed>
     */
    public function propertiesForQueue(string $queue): array
    {
        foreach ($this->declarationSteps() as $step) {
            if (($step['queue'] ?? '') === $queue) {
                return $step;
            }
        }

        throw new \InvalidArgumentException(sprintf('Queue [%s] is not part of this topology', $queue));
    }
}
