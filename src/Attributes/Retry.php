<?php

namespace Bschmitt\Amqp\Attributes;

use Bschmitt\Amqp\Support\RetryStrategy;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
/**
 * Declarative retry policy attribute.
 *
 *   #[Retry(attempts: 5, strategy: RetryStrategy::EXPONENTIAL, delayMs: 200)]
 *   public function handle(): void { ... }
 *
 * The `#[\Attribute]` marker is silently treated as a comment on PHP 7.x,
 * so the package itself loads on PHP 7.3+. Only PHP 8.0+ consumers can
 * actually attach the attribute to a class or method.
 *
 * Convert to a runtime {@see \Bschmitt\Amqp\Support\RetryPolicy} via
 * {@see \Bschmitt\Amqp\Support\RetryPolicy::fromAttribute()}.
 */
final class Retry
{
    /** @var int */
    public $attempts;

    /** @var string */
    public $strategy;

    /** @var int */
    public $delayMs;

    /** @var int */
    public $maxDelayMs;

    /** @var bool */
    public $jitter;

    /**
     * @param int    $attempts
     * @param string $strategy
     * @param int    $delayMs
     * @param int    $maxDelayMs
     * @param bool   $jitter
     */
    public function __construct(
        int $attempts = 3,
        string $strategy = RetryStrategy::EXPONENTIAL,
        int $delayMs = 1000,
        int $maxDelayMs = 60000,
        bool $jitter = false
    ) {
        if ($attempts < 1) {
            throw new \InvalidArgumentException('attempts must be >= 1');
        }
        if (!RetryStrategy::isValid($strategy)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown retry strategy [%s]; expected one of [%s]',
                $strategy,
                implode(', ', RetryStrategy::values())
            ));
        }

        $this->attempts = $attempts;
        $this->strategy = $strategy;
        $this->delayMs = max(0, $delayMs);
        $this->maxDelayMs = max($this->delayMs, $maxDelayMs);
        $this->jitter = $jitter;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attempts' => $this->attempts,
            'strategy' => $this->strategy,
            'delayMs' => $this->delayMs,
            'maxDelayMs' => $this->maxDelayMs,
            'jitter' => $this->jitter,
        ];
    }
}
