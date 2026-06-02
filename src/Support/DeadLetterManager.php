<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Events\DeadLetterDetected;
use Bschmitt\Amqp\Events\DeadLetterPurged;
use Bschmitt\Amqp\Events\DeadLetterReplayed;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Fluent dead-letter inspection and recovery.
 *
 *   Amqp::deadLetters()->for('orders.dlq')->count();
 *   Amqp::deadLetters()->for('orders.dlq')->peek(50);           // non-destructive
 *   Amqp::deadLetters()->for('orders.dlq')->summarize();        // categorize by reason / error
 *   Amqp::deadLetters()->for('orders.dlq')->messages(50);       // destructive drain
 *   Amqp::deadLetters()->for('orders.dlq')->replayTo('orders', 100);
 *   Amqp::deadLetters()->for('orders.dlq')->purge();
 *
 * Inspection uses the RabbitMQ Management HTTP API and direct AMQP
 * `basic_get` so `peek()` works even when the management plugin isn't
 * enabled. Lifecycle events are dispatched through the Laravel event
 * facade when available (see {@see EventDispatcher}).
 */
class DeadLetterManager
{
    /** @var Amqp */
    protected $amqp;

    /** @var string|null */
    protected $queue;

    /** @var array<string, mixed> */
    protected $properties = [];

    /**
     * @param Amqp $amqp
     */
    public function __construct(Amqp $amqp)
    {
        $this->amqp = $amqp;
    }

    /**
     * @param string $queue Dead-letter queue name.
     * @return $this
     */
    public function for(string $queue): self
    {
        if ($queue === '') {
            throw new \InvalidArgumentException('DLQ name must be non-empty');
        }
        $this->queue = $queue;

        return $this;
    }

    /**
     * Extra publish/consume properties (e.g. `use`, `exchange`).
     *
     * @param array<string, mixed> $properties
     * @return $this
     */
    public function withProperties(array $properties): self
    {
        $this->properties = array_merge($this->properties, $properties);

        return $this;
    }

    /**
     * Current message count in the DLQ.
     *
     * @return int
     */
    public function count(): int
    {
        $stats = $this->amqp->getQueueStatistics($this->guardQueue(), null, $this->properties);
        $count = (int) ($stats['messages'] ?? 0);

        if ($count > 0) {
            $this->dispatch(new DeadLetterDetected($this->guardQueue(), $count));
        }

        return $count;
    }

    /**
     * Non-destructive sample of the DLQ.
     *
     * Uses `basic_get` + `basic_reject(requeue=true)` so messages remain on
     * the queue. Order is not preserved across multiple `peek()` calls — the
     * broker decides where requeued messages re-land.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function peek(int $limit = 10): array
    {
        return $this->amqp->peekQueue($this->guardQueue(), $limit, $this->properties);
    }

    /**
     * Categorize a sample of dead-letter messages by reason, original queue,
     * and recent error text (read from `x-last-error` headers stamped by the
     * package's {@see RetryHandler}).
     *
     * @param int $sampleSize
     * @return array<string, mixed>
     */
    public function summarize(int $sampleSize = 100): array
    {
        $queue = $this->guardQueue();
        try {
            $sample = $this->peek($sampleSize);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        $byReason = [];
        $byOrigin = [];
        $byRouting = [];
        $byError = [];
        $oldestFailed = null;
        $maxAttempts = 0;

        foreach ($sample as $entry) {
            $headers = isset($entry['headers']) && is_array($entry['headers']) ? $entry['headers'] : [];
            $death = isset($headers['x-death'][0]) && is_array($headers['x-death'][0])
                ? $headers['x-death'][0]
                : [];

            $reason = isset($death['reason']) ? (string) $death['reason'] : 'unknown';
            $origin = isset($death['queue']) ? (string) $death['queue'] : 'unknown';
            $routing = isset($death['routing-keys'][0]) ? (string) $death['routing-keys'][0] : 'unknown';
            $error = isset($headers['x-last-error']) ? (string) $headers['x-last-error'] : 'unknown';

            $byReason[$reason] = (isset($byReason[$reason]) ? $byReason[$reason] : 0) + 1;
            $byOrigin[$origin] = (isset($byOrigin[$origin]) ? $byOrigin[$origin] : 0) + 1;
            $byRouting[$routing] = (isset($byRouting[$routing]) ? $byRouting[$routing] : 0) + 1;
            $byError[$error] = (isset($byError[$error]) ? $byError[$error] : 0) + 1;

            if (isset($headers['x-first-failed-at']) && is_numeric($headers['x-first-failed-at'])) {
                $ts = (int) $headers['x-first-failed-at'];
                if ($oldestFailed === null || $ts < $oldestFailed) {
                    $oldestFailed = $ts;
                }
            }

            if (isset($headers['x-retry-attempt']) && is_numeric($headers['x-retry-attempt'])) {
                $attempts = (int) $headers['x-retry-attempt'];
                if ($attempts > $maxAttempts) {
                    $maxAttempts = $attempts;
                }
            }
        }

        $summary = [
            'sampled' => count($sample),
            'by_reason' => $byReason,
            'by_origin' => $byOrigin,
            'by_routing_key' => $byRouting,
            'top_errors' => $this->topN($byError, 5),
            'oldest_failed_at' => $oldestFailed,
            'max_retry_attempt' => $maxAttempts,
        ];

        $this->dispatch(new DeadLetterDetected($queue, count($sample), $summary));

        return $summary;
    }

    /**
     * Drain up to `$limit` messages from the DLQ, returning the raw array.
     *
     * Each entry is `['body' => string, 'properties' => array, 'headers' => array]`.
     * Messages are acknowledged after being read; pass `requeue: true` to
     * push them back onto the DLQ before returning.
     *
     * @param int  $limit
     * @param bool $requeue
     * @return array<int, array<string, mixed>>
     */
    public function messages(int $limit = 10, bool $requeue = false): array
    {
        $queue = $this->guardQueue();
        $collected = [];

        $properties = array_merge($this->properties, [
            'queue' => $queue,
            'queue_force_declare' => true,
        ]);

        $this->amqp->consume($queue, function (AMQPMessage $message, $consumer) use (&$collected, $limit, $requeue) {
            $collected[] = [
                'body' => (string) $message->body,
                'properties' => $message->get_properties(),
                'headers' => MessageHeaders::toArray($message),
            ];

            if ($requeue) {
                $consumer->reject($message, true);
            } else {
                $consumer->acknowledge($message);
            }

            if (count($collected) >= $limit) {
                $consumer->stopWhenProcessed();
            }
        }, array_merge($properties, [
            'persistent' => false,
            'timeout' => 1,
            'stop_when_processed' => true,
        ]));

        return $collected;
    }

    /**
     * Replay (republish) up to `$limit` DLQ messages to a target queue.
     *
     * @param string   $targetQueue   Where to republish each message.
     * @param int      $limit         Max number to drain.
     * @param string   $exchange      Publish exchange (default: empty / direct).
     * @return int                    Number of messages replayed.
     */
    public function replayTo(string $targetQueue, int $limit = 100, string $exchange = ''): int
    {
        if ($targetQueue === '') {
            throw new \InvalidArgumentException('Replay target queue must be non-empty');
        }

        $queue = $this->guardQueue();
        $messages = $this->messages($limit);

        foreach ($messages as $entry) {
            $properties = array_merge($this->properties, [
                'exchange' => $exchange,
            ]);

            if (!empty($entry['headers'])) {
                $properties['application_headers'] = $entry['headers'];
            }
            if (!empty($entry['properties']['content_type'])) {
                $properties['content_type'] = $entry['properties']['content_type'];
            }

            $this->amqp->publish($targetQueue, $entry['body'], $properties);
        }

        $count = count($messages);
        $this->dispatch(new DeadLetterReplayed($queue, $targetQueue, $count));

        return $count;
    }

    /**
     * Drop every message currently on the DLQ.
     *
     * @return int Messages purged.
     */
    public function purge(): int
    {
        $queue = $this->guardQueue();
        $count = $this->amqp->queuePurge($queue, $this->properties);
        $this->dispatch(new DeadLetterPurged($queue, $count));

        return $count;
    }

    /**
     * @return string
     */
    protected function guardQueue(): string
    {
        if ($this->queue === null) {
            throw new \LogicException('Call for($dlqName) before invoking DLQ operations');
        }

        return $this->queue;
    }

    /**
     * @param object $event
     * @return void
     */
    protected function dispatch($event): void
    {
        EventDispatcher::instance()->dispatch($event);
    }

    /**
     * @param array<string, int> $counts
     * @param int                $n
     * @return array<string, int>
     */
    protected function topN(array $counts, int $n): array
    {
        arsort($counts);
        return array_slice($counts, 0, max(1, $n), true);
    }
}
