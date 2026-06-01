<?php

namespace Bschmitt\Amqp\Support;

/**
 * Declarative presets for RabbitMQ queue arguments (`queue_properties`).
 *
 * Complements raw `queue_properties` arrays with tested recipes for
 * quorum queues, priority queues, and common combinations.
 */
class QueueProfile
{
    /** @var array<string, mixed> */
    protected $arguments = [];

    /**
     * Classic queue (RabbitMQ default).
     *
     * @return self
     */
    public static function classic(): self
    {
        return new self();
    }

    /**
     * Quorum queue (`x-queue-type` = quorum).
     *
     * @return self
     */
    public static function quorum(): self
    {
        $profile = new self();
        $profile->arguments['x-queue-type'] = 'quorum';

        return $profile;
    }

    /**
     * Priority queue on a classic queue.
     *
     * @param int $maxPriority Maximum priority level (1-255).
     * @return self
     */
    public static function priority(int $maxPriority = 10): self
    {
        if ($maxPriority < 1 || $maxPriority > 255) {
            throw new \InvalidArgumentException('maxPriority must be between 1 and 255');
        }

        $profile = new self();
        $profile->arguments['x-max-priority'] = $maxPriority;

        return $profile;
    }

    /**
     * Quorum queue with priority support (RabbitMQ 3.10+).
     *
     * @param int $maxPriority
     * @return self
     */
    public static function quorumWithPriority(int $maxPriority = 10): self
    {
        return self::quorum()->withPriority($maxPriority);
    }

    /**
     * @param int $maxPriority
     * @return $this
     */
    public function withPriority(int $maxPriority): self
    {
        if ($maxPriority < 1 || $maxPriority > 255) {
            throw new \InvalidArgumentException('maxPriority must be between 1 and 255');
        }
        $this->arguments['x-max-priority'] = $maxPriority;

        return $this;
    }

    /**
     * Merge additional queue arguments.
     *
     * @param array<string, mixed> $arguments
     * @return $this
     */
    public function withArguments(array $arguments): self
    {
        $this->arguments = array_merge($this->arguments, $arguments);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toQueueProperties(): array
    {
        return $this->arguments;
    }

    /**
     * Merge into a publisher/consumer properties bag.
     *
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    public function mergeInto(array $properties): array
    {
        $existing = (array) ($properties['queue_properties'] ?? []);
        $properties['queue_properties'] = array_merge($existing, $this->arguments);

        return $properties;
    }
}
