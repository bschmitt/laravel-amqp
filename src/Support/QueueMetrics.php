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

        return new self(
            (string) ($apiResponse['name'] ?? ''),
            (string) ($apiResponse['vhost'] ?? '/'),
            (int) ($apiResponse['messages'] ?? 0),
            (int) ($apiResponse['messages_ready'] ?? 0),
            (int) ($apiResponse['messages_unacknowledged'] ?? 0),
            (int) ($apiResponse['consumers'] ?? 0),
            $publishRate,
            $deliverRate,
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'vhost' => $this->vhost,
            'messages' => $this->messages,
            'messages_ready' => $this->messagesReady,
            'messages_unacknowledged' => $this->messagesUnacknowledged,
            'consumers' => $this->consumers,
            'publish_rate' => $this->publishRate,
            'deliver_rate' => $this->deliverRate,
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
