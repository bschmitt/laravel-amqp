<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Core\Amqp;

/**
 * Aggregated snapshot of broker + in-process metrics, suitable for a
 * Horizon-style dashboard or a JSON status endpoint.
 *
 *   $snapshot = Amqp::dashboard(['orders', 'orders.dlq'])->snapshot();
 *
 *   $snapshot = [
 *     'process'   => ['published' => 12, 'consumed' => 11, 'failed' => 1, ...],
 *     'queues'    => [
 *       'orders'     => ['messages' => 3, 'consumers' => 2, 'rate' => 4.5, ...],
 *       'orders.dlq' => ['messages' => 1, ...],
 *     ],
 *     'overview'  => [...],
 *     'generated' => '2026-06-02T05:32:11+00:00',
 *   ];
 *
 * The Management HTTP API is used for broker stats; failures fall back to
 * an empty queue snapshot so consumers can still surface partial data.
 */
class MonitoringDashboard
{
    /** @var Amqp */
    protected $amqp;

    /** @var array<int, string> */
    protected $queues;

    /** @var array<int, string> */
    protected $deadLetterQueues = [];

    /** @var array<string, mixed> */
    protected $properties;

    /** @var int|null */
    protected $lagBacklogThreshold;

    /** @var float|null */
    protected $lagSecondsThreshold;

    /** @var int|null */
    protected $lagAgeThreshold;

    /**
     * @param Amqp                 $amqp
     * @param array<int, string>   $queues
     * @param array<string, mixed> $properties
     */
    public function __construct(Amqp $amqp, array $queues = [], array $properties = [])
    {
        $this->amqp = $amqp;
        $this->queues = array_values(array_filter($queues, 'is_string'));
        $this->properties = $properties;
    }

    /**
     * @param string $queue
     * @return $this
     */
    public function watch(string $queue): self
    {
        if ($queue !== '' && !in_array($queue, $this->queues, true)) {
            $this->queues[] = $queue;
        }

        return $this;
    }

    /**
     * Treat one or more queues as dead-letter queues. Their summary appears
     * in the `dead_letters` block of {@see snapshot()} (in addition to the
     * regular per-queue stats).
     *
     * @param array<int, string>|string $queues
     * @return $this
     */
    public function deadLetters($queues): self
    {
        $list = is_array($queues) ? $queues : [$queues];
        foreach ($list as $queue) {
            if (is_string($queue) && $queue !== '') {
                if (!in_array($queue, $this->deadLetterQueues, true)) {
                    $this->deadLetterQueues[] = $queue;
                }
                $this->watch($queue);
            }
        }

        return $this;
    }

    /**
     * Set thresholds used by {@see laggingQueues()} and the
     * `lagging` flag inside {@see queueSnapshots()}.
     *
     * @param int|null   $backlog   Max acceptable `messages_ready + unacked`.
     * @param float|null $seconds   Max acceptable time-to-drain in seconds.
     * @param int|null   $ageSeconds Max acceptable head-of-queue age in seconds.
     * @return $this
     */
    public function lagThresholds(?int $backlog = null, ?float $seconds = null, ?int $ageSeconds = null): self
    {
        $this->lagBacklogThreshold = $backlog;
        $this->lagSecondsThreshold = $seconds;
        $this->lagAgeThreshold = $ageSeconds;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $queueSnapshots = $this->queueSnapshots();
        $out = [
            'process' => $this->processSnapshot(),
            'queues' => $queueSnapshots,
            'overview' => $this->overviewSnapshot(),
            'generated' => date('c'),
        ];

        if ($this->deadLetterQueues !== []) {
            $out['dead_letters'] = $this->deadLetterSnapshot($queueSnapshots);
        }

        $lagging = $this->laggingQueuesFromSnapshot($queueSnapshots);
        if ($lagging !== []) {
            $out['lagging'] = $lagging;
        }

        $rpc = $this->amqp->rpcMetrics()->snapshot();
        if ($rpc !== []) {
            $out['rpc'] = $rpc;
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    public function processSnapshot(): array
    {
        return $this->amqp->metrics()->snapshot();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function queueSnapshots(): array
    {
        $out = [];

        foreach ($this->queues as $queue) {
            try {
                $stats = $this->amqp->getQueueStatistics($queue, null, $this->properties);
                $metrics = QueueMetrics::fromManagementApi($stats);
                $row = $metrics->toArray();
                $row['lagging'] = $metrics->isLagging(
                    $this->lagBacklogThreshold !== null ? $this->lagBacklogThreshold : PHP_INT_MAX,
                    $this->lagSecondsThreshold,
                    $this->lagAgeThreshold
                );
                $out[$queue] = $row;
            } catch (\Throwable $e) {
                $out[$queue] = [
                    'name' => $queue,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $out;
    }

    /**
     * Names of queues that breach the configured lag thresholds.
     *
     * @return array<int, string>
     */
    public function laggingQueues(): array
    {
        return $this->laggingQueuesFromSnapshot($this->queueSnapshots());
    }

    /**
     * Cluster-wide overview (if Management API is reachable).
     *
     * @return array<string, mixed>
     */
    public function overviewSnapshot(): array
    {
        try {
            $client = $this->amqp->getQueueStatistics();

            return [
                'queues' => is_array($client) ? count($client) : 0,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $queueSnapshots
     * @return array<int, string>
     */
    protected function laggingQueuesFromSnapshot(array $queueSnapshots): array
    {
        if ($this->lagBacklogThreshold === null
            && $this->lagSecondsThreshold === null
            && $this->lagAgeThreshold === null) {
            return [];
        }

        $out = [];
        foreach ($queueSnapshots as $name => $row) {
            if (!empty($row['lagging'])) {
                $out[] = (string) $name;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $queueSnapshots
     * @return array<string, array<string, mixed>>
     */
    protected function deadLetterSnapshot(array $queueSnapshots): array
    {
        $out = [];
        foreach ($this->deadLetterQueues as $queue) {
            $row = isset($queueSnapshots[$queue]) ? $queueSnapshots[$queue] : null;
            if ($row === null) {
                $out[$queue] = ['name' => $queue, 'error' => 'no stats'];
                continue;
            }
            try {
                $summary = $this->amqp->deadLetters()
                    ->for($queue)
                    ->withProperties($this->properties)
                    ->summarize();
            } catch (\Throwable $e) {
                $summary = ['error' => $e->getMessage()];
            }

            $out[$queue] = [
                'name' => $queue,
                'messages' => isset($row['messages']) ? $row['messages'] : null,
                'consumers' => isset($row['consumers']) ? $row['consumers'] : null,
                'summary' => $summary,
            ];
        }

        return $out;
    }
}
