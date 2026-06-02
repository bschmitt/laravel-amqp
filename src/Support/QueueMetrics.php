<?php

namespace Bschmitt\Amqp\Support;

/**
 * Normalized queue statistics from the RabbitMQ Management API.
 */
class QueueMetrics
{
    /** @var string */
    protected $name;

    /** @var string */
    protected $vhost;

    /** @var int */
    protected $messages;

    /** @var int */
    protected $messagesReady;

    /** @var int */
    protected $messagesUnacknowledged;

    /** @var int */
    protected $consumers;

    /** @var float|null */
    protected $publishRate;

    /** @var float|null */
    protected $deliverRate;

    /**
     * Unix timestamp (seconds) of the message currently at the head of the
     * queue, if RabbitMQ exposes it. Used to derive head-of-queue age.
     *
     * @var int|null
     */
    protected $headMessageTimestamp;

    /** @var array<string, mixed> */
    protected $raw;

    /**
     * @param string               $name
     * @param string               $vhost
     * @param int                  $messages
     * @param int                  $messagesReady
     * @param int                  $messagesUnacknowledged
     * @param int                  $consumers
     * @param float|null           $publishRate
     * @param float|null           $deliverRate
     * @param int|null             $headMessageTimestamp Unix seconds (optional).
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $name,
        string $vhost,
        int $messages,
        int $messagesReady,
        int $messagesUnacknowledged,
        int $consumers,
        ?float $publishRate,
        ?float $deliverRate,
        ?int $headMessageTimestamp = null,
        array $raw = []
    ) {
        $this->name = $name;
        $this->vhost = $vhost;
        $this->messages = $messages;
        $this->messagesReady = $messagesReady;
        $this->messagesUnacknowledged = $messagesUnacknowledged;
        $this->consumers = $consumers;
        $this->publishRate = $publishRate;
        $this->deliverRate = $deliverRate;
        $this->headMessageTimestamp = $headMessageTimestamp;
        $this->raw = $raw;
    }

    /**
     * @param array<string, mixed> $apiResponse Single queue object from Management API.
     * @return self
     */
    public static function fromManagementApi(array $apiResponse): self
    {
        $rates = (array) ($apiResponse['message_stats'] ?? []);
        $publishRate = isset($rates['publish_details']['rate'])
            ? (float) $rates['publish_details']['rate']
            : null;
        $deliverRate = isset($rates['deliver_get_details']['rate'])
            ? (float) $rates['deliver_get_details']['rate']
            : null;

        $headTs = null;
        if (isset($apiResponse['head_message_timestamp'])
            && is_numeric($apiResponse['head_message_timestamp'])) {
            $headTs = (int) $apiResponse['head_message_timestamp'];
        }

        return new self(
            (string) ($apiResponse['name'] ?? ''),
            (string) ($apiResponse['vhost'] ?? '/'),
            (int) ($apiResponse['messages'] ?? 0),
            (int) ($apiResponse['messages_ready'] ?? 0),
            (int) ($apiResponse['messages_unacknowledged'] ?? 0),
            (int) ($apiResponse['consumers'] ?? 0),
            $publishRate,
            $deliverRate,
            $headTs,
            $apiResponse
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function vhost(): string
    {
        return $this->vhost;
    }

    public function messageCount(): int
    {
        return $this->messages;
    }

    public function messagesReady(): int
    {
        return $this->messagesReady;
    }

    public function messagesUnacknowledged(): int
    {
        return $this->messagesUnacknowledged;
    }

    public function consumerCount(): int
    {
        return $this->consumers;
    }

    public function publishRate(): ?float
    {
        return $this->publishRate;
    }

    public function deliverRate(): ?float
    {
        return $this->deliverRate;
    }

    /**
     * Unix timestamp (seconds) of the head-of-queue message, if the broker
     * exposes one. RabbitMQ Management API populates `head_message_timestamp`
     * only when consumers/management plugins ask for it and the head message
     * has a `timestamp` property — return null when unavailable.
     *
     * @return int|null
     */
    public function headMessageTimestamp(): ?int
    {
        return $this->headMessageTimestamp;
    }

    /**
     * Total backlog the queue still has to deliver: `ready + unacked`.
     *
     * This is the canonical "lag" metric for AMQP — Kafka-style oldest-
     * message age is exposed separately via {@see oldestMessageAgeSeconds()}.
     *
     * @return int
     */
    public function lag(): int
    {
        return $this->messagesReady + $this->messagesUnacknowledged;
    }

    /**
     * Estimated time, in seconds, for current consumers to drain the backlog
     * given the most recent deliver rate.
     *
     * Returns `null` when no deliver rate is reported (e.g. no consumers
     * attached, or Management API rates disabled). Returns `+INF` when there
     * is a backlog but no deliver throughput at all.
     *
     * @return float|null
     */
    public function lagSeconds(): ?float
    {
        if ($this->deliverRate === null) {
            return null;
        }
        if ($this->deliverRate <= 0.0) {
            return $this->lag() > 0 ? INF : 0.0;
        }

        return $this->lag() / $this->deliverRate;
    }

    /**
     * Age, in seconds, of the message currently at the head of the queue.
     *
     * @param int|null $now Unix seconds (defaults to current time, mostly for tests).
     * @return int|null
     */
    public function oldestMessageAgeSeconds(?int $now = null): ?int
    {
        if ($this->headMessageTimestamp === null) {
            return null;
        }

        $reference = $now !== null ? $now : time();
        $age = $reference - $this->headMessageTimestamp;

        return $age < 0 ? 0 : $age;
    }

    /**
     * Threshold check used by dashboards / Artisan commands / alerting.
     *
     * @param int        $maxBacklog       Backlog size (ready + unacked) that counts as lagging.
     * @param float|null $maxLagSeconds    Optional time-to-drain threshold in seconds.
     * @param int|null   $maxAgeSeconds    Optional head-of-queue age threshold in seconds.
     * @return bool
     */
    public function isLagging(int $maxBacklog, ?float $maxLagSeconds = null, ?int $maxAgeSeconds = null): bool
    {
        if ($maxBacklog >= 0 && $this->lag() > $maxBacklog) {
            return true;
        }
        if ($maxLagSeconds !== null) {
            $secs = $this->lagSeconds();
            if ($secs !== null && $secs > $maxLagSeconds) {
                return true;
            }
        }
        if ($maxAgeSeconds !== null) {
            $age = $this->oldestMessageAgeSeconds();
            if ($age !== null && $age > $maxAgeSeconds) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $lagSeconds = $this->lagSeconds();

        return [
            'name' => $this->name,
            'vhost' => $this->vhost,
            'messages' => $this->messages,
            'messages_ready' => $this->messagesReady,
            'messages_unacknowledged' => $this->messagesUnacknowledged,
            'consumers' => $this->consumers,
            'publish_rate' => $this->publishRate,
            'deliver_rate' => $this->deliverRate,
            'lag' => $this->lag(),
            'lag_seconds' => is_finite((float) $lagSeconds) ? $lagSeconds : null,
            'head_message_timestamp' => $this->headMessageTimestamp,
            'oldest_message_age_seconds' => $this->oldestMessageAgeSeconds(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
