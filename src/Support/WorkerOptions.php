<?php

namespace Bschmitt\Amqp\Support;

/**
 * Tuned consumer property presets for high-throughput workers.
 */
class WorkerOptions
{
    /** @var int */
    protected $prefetchCount = 10;

    /** @var bool */
    protected $qosGlobal = false;

    /** @var bool */
    protected $persistentConnection = false;

    /** @var string|null */
    protected $connectionPoolKey;

    /**
     * High throughput: prefetch many messages per consumer.
     *
     * @param int $prefetchCount
     * @return self
     */
    public static function throughput(int $prefetchCount = 50): self
    {
        $options = new self();
        $options->prefetchCount = max(1, $prefetchCount);

        return $options;
    }

    /**
     * Low latency: prefetch one message at a time.
     *
     * @return self
     */
    public static function lowLatency(): self
    {
        $options = new self();
        $options->prefetchCount = 1;

        return $options;
    }

    /**
     * @param string $poolKey Connection pool key for {@see ConnectionPool}.
     * @return $this
     */
    public function persistentConnection(string $poolKey = 'worker'): self
    {
        $this->persistentConnection = true;
        $this->connectionPoolKey = $poolKey;

        return $this;
    }

    /**
     * @param bool $global
     * @return $this
     */
    public function qosGlobal(bool $global = true): self
    {
        $this->qosGlobal = $global;

        return $this;
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    public function mergeInto(array $properties): array
    {
        $properties['qos'] = true;
        $properties['qos_prefetch_count'] = $this->prefetchCount;
        $properties['qos_global'] = $this->qosGlobal;

        if ($this->persistentConnection) {
            $properties['__worker_persistent_pool'] = $this->connectionPoolKey !== null
                ? $this->connectionPoolKey
                : 'worker';
        }

        return $properties;
    }

    public function prefetchCount(): int
    {
        return $this->prefetchCount;
    }
}
