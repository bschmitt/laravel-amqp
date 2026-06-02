<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Core\Amqp;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Fluent dead-letter inspection and recovery.
 *
 *   Amqp::deadLetters()->for('orders.dlq')->count();
 *   Amqp::deadLetters()->for('orders.dlq')->messages(50);
 *   Amqp::deadLetters()->for('orders.dlq')->replayTo('orders', 100);
 *   Amqp::deadLetters()->for('orders.dlq')->purge();
 *
 * Inspection uses the RabbitMQ Management HTTP API; replay/purge use the
 * AMQP channel directly so they work even when the Management plugin isn't
 * enabled.
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

        return (int) ($stats['messages'] ?? 0);
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

        return count($messages);
    }

    /**
     * Drop every message currently on the DLQ.
     *
     * @return int Messages purged.
     */
    public function purge(): int
    {
        return $this->amqp->queuePurge($this->guardQueue(), $this->properties);
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
}
