<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\ConnectionManagerInterface;
use Bschmitt\Amqp\Core\Amqp;

/**
 * Composable liveness/readiness probe for AMQP workloads.
 *
 * Combines:
 *  - {@see HealthState} (in-process worker state stamped by lifecycle hooks)
 *  - broker connectivity (optional, via {@see ConnectionManagerInterface})
 *  - queue-existence + depth checks (optional, via {@see Amqp::getQueueStatistics()})
 *
 * Each check produces a `(ok, message)` tuple aggregated into a structured
 * health report consumed by both the HTTP controller and the `amqp:health`
 * CLI command.
 */
class HealthCheck
{
    /** @var HealthState */
    protected $state;

    /** @var Amqp|null */
    protected $amqp;

    /** @var ConnectionManagerInterface|null */
    protected $connections;

    /** @var array<int, string> */
    protected $watchedQueues = [];

    /** @var float */
    protected $maxHeartbeatAgeSeconds = 60.0;

    /** @var int|null */
    protected $maxBacklog;

    public function __construct(
        HealthState $state,
        ?Amqp $amqp = null,
        ?ConnectionManagerInterface $connections = null
    ) {
        $this->state = $state;
        $this->amqp = $amqp;
        $this->connections = $connections;
    }

    /**
     * @param array<int, string> $queues
     * @return $this
     */
    public function watchQueues(array $queues): self
    {
        $this->watchedQueues = array_values(array_filter(array_map('strval', $queues), function ($q) {
            return $q !== '';
        }));
        return $this;
    }

    public function maxHeartbeatAge(float $seconds): self
    {
        $this->maxHeartbeatAgeSeconds = max(0.0, $seconds);
        return $this;
    }

    public function maxBacklog(?int $messages): self
    {
        $this->maxBacklog = $messages;
        return $this;
    }

    /**
     * Liveness check: should Kubernetes restart this pod?
     *
     * Returns false when the worker is explicitly marked dead OR has gone
     * silent for longer than the configured heartbeat threshold.
     *
     * @return array<string, mixed>
     */
    public function liveness(): array
    {
        $checks = [];

        $alive = $this->state->isAlive();
        $checks[] = $this->check('process_alive', $alive, $alive ? 'process alive' : ($this->state->reason() ?: 'marked dead'));

        $age = $this->state->ageSinceHeartbeat();
        $heartbeatOk = $this->maxHeartbeatAgeSeconds <= 0.0 || $age <= $this->maxHeartbeatAgeSeconds;
        $checks[] = $this->check(
            'heartbeat',
            $heartbeatOk,
            $heartbeatOk
                ? sprintf('heartbeat %.1fs ago', $age)
                : sprintf('no heartbeat for %.1fs (threshold %.1fs)', $age, $this->maxHeartbeatAgeSeconds)
        );

        return $this->summarize('liveness', $checks);
    }

    /**
     * Readiness check: should Kubernetes route traffic to this pod?
     *
     * Returns true only when liveness is healthy, the worker has been marked
     * ready, the broker connection works, and watched queues exist.
     *
     * @return array<string, mixed>
     */
    public function readiness(): array
    {
        $checks = [];

        $live = $this->liveness();
        $checks[] = $this->check(
            'liveness',
            $live['ok'],
            $live['ok'] ? 'live' : 'not live'
        );

        $checks[] = $this->check(
            'worker_ready',
            $this->state->isReady(),
            $this->state->isReady()
                ? 'consumer marked ready'
                : ($this->state->reason() ?: 'consumer not marked ready')
        );

        if ($this->connections !== null) {
            $connected = false;
            $reason = 'unknown';
            try {
                $connected = $this->connections->isConnected();
                $reason = $connected ? 'broker connected' : 'broker not connected';
            } catch (\Throwable $e) {
                $reason = 'broker check failed: ' . $e->getMessage();
            }
            $checks[] = $this->check('broker_connection', $connected, $reason);
        }

        foreach ($this->watchedQueues as $queue) {
            $checks[] = $this->checkQueue($queue);
        }

        return $this->summarize('readiness', $checks);
    }

    /**
     * Combined snapshot — useful for `amqp:health --all` and JSON dumps.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'liveness' => $this->liveness(),
            'readiness' => $this->readiness(),
            'state' => $this->state->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkQueue(string $queue): array
    {
        if ($this->amqp === null) {
            return $this->check('queue:' . $queue, false, 'Amqp client unavailable');
        }

        try {
            $stats = $this->amqp->getQueueStatistics($queue);
        } catch (\Throwable $e) {
            return $this->check('queue:' . $queue, false, 'queue check failed: ' . $e->getMessage());
        }

        if (!is_array($stats) || $stats === []) {
            return $this->check('queue:' . $queue, false, 'queue not found');
        }

        $messages = (int) ($stats['messages'] ?? 0);
        if ($this->maxBacklog !== null && $messages > $this->maxBacklog) {
            return $this->check(
                'queue:' . $queue,
                false,
                sprintf('backlog %d exceeds %d', $messages, $this->maxBacklog),
                ['messages' => $messages, 'threshold' => $this->maxBacklog]
            );
        }

        return $this->check(
            'queue:' . $queue,
            true,
            sprintf('queue ok (%d msgs)', $messages),
            ['messages' => $messages]
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function check(string $name, bool $ok, string $message, array $context = []): array
    {
        return [
            'name' => $name,
            'ok' => $ok,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     * @return array<string, mixed>
     */
    protected function summarize(string $kind, array $checks): array
    {
        $ok = true;
        foreach ($checks as $check) {
            if (empty($check['ok'])) {
                $ok = false;
                break;
            }
        }

        return [
            'kind' => $kind,
            'ok' => $ok,
            'checks' => $checks,
        ];
    }
}
