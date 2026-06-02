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

    /** @var array<string, mixed> */
    protected $properties;

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
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'process' => $this->processSnapshot(),
            'queues' => $this->queueSnapshots(),
            'overview' => $this->overviewSnapshot(),
            'generated' => date('c'),
        ];
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
                $out[$queue] = QueueMetrics::fromManagementApi($stats)->toArray();
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
}
