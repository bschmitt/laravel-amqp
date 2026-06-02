<?php

namespace Bschmitt\Amqp\Support;

/**
 * In-process counters for publish/consume observability.
 *
 * Pair with Laravel events or export {@see snapshot()} to Prometheus,
 * StatsD, or logs. Safe for one-worker-per-process PHP models.
 */
class MetricsCollector
{
    /** @var int */
    protected $published = 0;

    /** @var int */
    protected $consumed = 0;

    /** @var int */
    protected $handled = 0;

    /** @var int */
    protected $failed = 0;

    /** @var array<string, int> */
    protected $publishedByRouting = [];

    /** @var array<string, int> */
    protected $consumedByQueue = [];

    /**
     * @param string $routing
     * @return void
     */
    public function incrementPublished(string $routing = ''): void
    {
        $this->published++;
        if ($routing !== '') {
            if (!isset($this->publishedByRouting[$routing])) {
                $this->publishedByRouting[$routing] = 0;
            }
            $this->publishedByRouting[$routing]++;
        }
    }

    /**
     * @param string $queue
     * @return void
     */
    public function incrementConsumed(string $queue = ''): void
    {
        $this->consumed++;
        if ($queue !== '') {
            if (!isset($this->consumedByQueue[$queue])) {
                $this->consumedByQueue[$queue] = 0;
            }
            $this->consumedByQueue[$queue]++;
        }
    }

    public function incrementHandled(): void
    {
        $this->handled++;
    }

    /**
     * @param string $queue
     * @return void
     */
    public function incrementFailed(string $queue = ''): void
    {
        $this->failed++;
        if ($queue !== '') {
            $key = $queue.':failed';
            if (!isset($this->consumedByQueue[$key])) {
                $this->consumedByQueue[$key] = 0;
            }
            $this->consumedByQueue[$key]++;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'published' => $this->published,
            'consumed' => $this->consumed,
            'handled' => $this->handled,
            'failed' => $this->failed,
            'published_by_routing' => $this->publishedByRouting,
            'consumed_by_queue' => $this->consumedByQueue,
        ];
    }

    /**
     * @return void
     */
    public function reset(): void
    {
        $this->published = 0;
        $this->consumed = 0;
        $this->handled = 0;
        $this->failed = 0;
        $this->publishedByRouting = [];
        $this->consumedByQueue = [];
    }
}
