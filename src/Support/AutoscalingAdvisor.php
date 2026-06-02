<?php

namespace Bschmitt\Amqp\Support;

/**
 * Compute consumer-count recommendations from {@see QueueMetrics}.
 *
 * Pure function-style: no broker calls, no side effects. Feed it a snapshot
 * (or an array shaped like `QueueMetrics::toArray()`) and it emits a desired
 * replica count plus a human-readable explanation, plus a KEDA-style trigger
 * spec callers can use to wire up the Kubernetes HPA.
 *
 * Heuristics (defaults are conservative, tunable per call):
 *  - target depth per consumer (`messagesPerConsumer`)
 *  - target lag threshold (`maxLagSeconds`) — when the head message is older
 *    than this, we scale up by 1 even if depth is within budget
 *  - hard min / max bounds
 */
class AutoscalingAdvisor
{
    /** @var int */
    protected $minReplicas = 1;

    /** @var int */
    protected $maxReplicas = 10;

    /** @var int */
    protected $messagesPerConsumer = 100;

    /** @var float|null */
    protected $maxLagSeconds = 30.0;

    /** @var int */
    protected $scaleDownGraceMessages = 10;

    public function minReplicas(int $value): self
    {
        $this->minReplicas = max(0, $value);
        return $this;
    }

    public function maxReplicas(int $value): self
    {
        $this->maxReplicas = max(1, $value);
        return $this;
    }

    public function messagesPerConsumer(int $value): self
    {
        $this->messagesPerConsumer = max(1, $value);
        return $this;
    }

    public function maxLagSeconds(?float $seconds): self
    {
        $this->maxLagSeconds = $seconds;
        return $this;
    }

    public function scaleDownGrace(int $messages): self
    {
        $this->scaleDownGraceMessages = max(0, $messages);
        return $this;
    }

    /**
     * @param QueueMetrics|array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    public function advise($metrics): array
    {
        $snapshot = $metrics instanceof QueueMetrics ? $metrics->toArray() : (array) $metrics;
        $name = (string) ($snapshot['name'] ?? 'unknown');
        $messages = max(0, (int) ($snapshot['messages'] ?? 0));
        $consumers = max(0, (int) ($snapshot['consumers'] ?? 0));
        $lagSeconds = isset($snapshot['lag_seconds']) && is_numeric($snapshot['lag_seconds'])
            ? (float) $snapshot['lag_seconds']
            : null;

        $byDepth = (int) ceil($messages / max(1, $this->messagesPerConsumer));

        $desired = $byDepth;
        $reasons = [];
        if ($messages > 0) {
            $reasons[] = sprintf(
                'depth %d / %d msg per consumer -> %d',
                $messages,
                $this->messagesPerConsumer,
                $byDepth
            );
        }

        if ($this->maxLagSeconds !== null && $lagSeconds !== null && $lagSeconds > $this->maxLagSeconds) {
            $desired = max($desired, $consumers > 0 ? $consumers + 1 : 1);
            $reasons[] = sprintf(
                'lag %.1fs > %.1fs threshold -> +1 over current %d',
                $lagSeconds,
                $this->maxLagSeconds,
                $consumers
            );
        }

        // Scale-down grace: don't drop to zero when there's still a tiny tail.
        if ($desired < $this->minReplicas) {
            $desired = $this->minReplicas;
            $reasons[] = sprintf('floored at min_replicas=%d', $this->minReplicas);
        }
        if ($desired > $this->maxReplicas) {
            $desired = $this->maxReplicas;
            $reasons[] = sprintf('capped at max_replicas=%d', $this->maxReplicas);
        }
        if ($messages > 0 && $messages <= $this->scaleDownGraceMessages && $desired < max(1, $consumers)) {
            $desired = max(1, $consumers);
            $reasons[] = sprintf(
                'scale-down grace (%d <= %d msgs) -> keep %d consumers',
                $messages,
                $this->scaleDownGraceMessages,
                $desired
            );
        }
        if ($desired < 0) {
            $desired = 0;
        }

        $action = 'hold';
        if ($desired > $consumers) {
            $action = 'scale_up';
        } elseif ($desired < $consumers) {
            $action = 'scale_down';
        }

        return [
            'queue' => $name,
            'messages' => $messages,
            'lag_seconds' => $lagSeconds,
            'current_consumers' => $consumers,
            'desired_consumers' => $desired,
            'action' => $action,
            'reasons' => $reasons,
            'keda' => $this->kedaTrigger($name, $snapshot),
        ];
    }

    /**
     * Return a KEDA RabbitMQ ScaledObject trigger spec for the given queue.
     *
     * @param string               $queue
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function kedaTrigger(string $queue, array $snapshot): array
    {
        $vhost = (string) ($snapshot['vhost'] ?? '/');
        return [
            'type' => 'rabbitmq',
            'metadata' => [
                'queueName' => $queue,
                'vhostName' => $vhost,
                'mode' => 'QueueLength',
                'value' => (string) $this->messagesPerConsumer,
            ],
            'spec' => [
                'minReplicaCount' => $this->minReplicas,
                'maxReplicaCount' => $this->maxReplicas,
            ],
        ];
    }
}
