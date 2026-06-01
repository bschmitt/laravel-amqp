<?php

namespace Bschmitt\Amqp\Support;

use InvalidArgumentException;

/**
 * Immutable retry policy used by {@see RetryHandler} and {@see DeadLetterTopology}
 * to decide how many times a failing message should be retried and how long to
 * wait between attempts.
 *
 * A policy is a pure value object — it knows nothing about AMQP or the broker
 * and never performs I/O. Backoff strategies map an attempt number (1-based,
 * i.e. the first retry is attempt #1) to a delay in milliseconds.
 *
 * Strategies:
 *  - "fixed":       always returns the configured base delay
 *  - "exponential": baseDelay * (multiplier ^ (attempt - 1)), optionally capped
 *
 * Convenience factories:
 *  - {@see RetryPolicy::fixed()}        equal delays between attempts
 *  - {@see RetryPolicy::exponential()}  growing delays with optional cap
 *  - {@see RetryPolicy::immediate()}    retries with zero delay
 *  - {@see RetryPolicy::none()}         disables retries entirely
 */
class RetryPolicy
{
    public const STRATEGY_FIXED = 'fixed';
    public const STRATEGY_EXPONENTIAL = 'exponential';

    /** @var int */
    protected $maxAttempts;

    /** @var int */
    protected $baseDelayMs;

    /** @var string */
    protected $strategy;

    /** @var int */
    protected $maxDelayMs;

    /** @var float */
    protected $multiplier;

    /** @var int */
    protected $jitterMs;

    /**
     * @param int    $maxAttempts How many retries (NOT counting the initial delivery). 0 disables retries.
     * @param int    $baseDelayMs Base delay between retries in milliseconds.
     * @param string $strategy    One of {@see RetryPolicy::STRATEGY_FIXED}, {@see RetryPolicy::STRATEGY_EXPONENTIAL}.
     * @param int    $maxDelayMs  Cap for the computed delay in milliseconds (0 = uncapped).
     * @param float  $multiplier  Growth factor for exponential backoff.
     * @param int    $jitterMs    Random jitter added on top of every delay (0 = deterministic).
     */
    public function __construct(
        int $maxAttempts,
        int $baseDelayMs = 1000,
        string $strategy = self::STRATEGY_FIXED,
        int $maxDelayMs = 0,
        float $multiplier = 2.0,
        int $jitterMs = 0
    ) {
        if ($maxAttempts < 0) {
            throw new InvalidArgumentException('maxAttempts must be >= 0');
        }
        if ($baseDelayMs < 0) {
            throw new InvalidArgumentException('baseDelayMs must be >= 0');
        }
        if ($maxDelayMs < 0) {
            throw new InvalidArgumentException('maxDelayMs must be >= 0');
        }
        if ($multiplier < 1.0) {
            throw new InvalidArgumentException('multiplier must be >= 1.0');
        }
        if ($jitterMs < 0) {
            throw new InvalidArgumentException('jitterMs must be >= 0');
        }
        if (!in_array($strategy, [self::STRATEGY_FIXED, self::STRATEGY_EXPONENTIAL], true)) {
            throw new InvalidArgumentException('Unsupported retry strategy: '.$strategy);
        }

        $this->maxAttempts = $maxAttempts;
        $this->baseDelayMs = $baseDelayMs;
        $this->strategy = $strategy;
        $this->maxDelayMs = $maxDelayMs;
        $this->multiplier = $multiplier;
        $this->jitterMs = $jitterMs;
    }

    /**
     * Fixed delay between every retry.
     */
    public static function fixed(int $maxAttempts, int $delayMs = 1000, int $jitterMs = 0): self
    {
        return new self($maxAttempts, $delayMs, self::STRATEGY_FIXED, 0, 2.0, $jitterMs);
    }

    /**
     * Exponential backoff: delay = baseDelay * multiplier^(attempt-1), capped at $maxDelayMs.
     */
    public static function exponential(
        int $maxAttempts,
        int $baseDelayMs = 1000,
        float $multiplier = 2.0,
        int $maxDelayMs = 0,
        int $jitterMs = 0
    ): self {
        return new self($maxAttempts, $baseDelayMs, self::STRATEGY_EXPONENTIAL, $maxDelayMs, $multiplier, $jitterMs);
    }

    /**
     * Retries with no delay — useful for transient race conditions only.
     */
    public static function immediate(int $maxAttempts): self
    {
        return new self($maxAttempts, 0, self::STRATEGY_FIXED, 0, 2.0, 0);
    }

    /**
     * Disables retries entirely — failures go straight to the DLQ.
     */
    public static function none(): self
    {
        return new self(0, 0, self::STRATEGY_FIXED, 0, 2.0, 0);
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function baseDelayMs(): int
    {
        return $this->baseDelayMs;
    }

    public function strategy(): string
    {
        return $this->strategy;
    }

    public function maxDelayMs(): int
    {
        return $this->maxDelayMs;
    }

    public function multiplier(): float
    {
        return $this->multiplier;
    }

    public function jitterMs(): int
    {
        return $this->jitterMs;
    }

    /**
     * Should the handler retry the message after $attempt failed attempts?
     *
     * "attempt" is 1-based: 1 means we have failed once and are deciding
     * whether to do the first retry.
     */
    public function shouldRetry(int $attempt): bool
    {
        return $attempt <= $this->maxAttempts;
    }

    /**
     * Compute the delay in milliseconds before performing attempt #$attempt.
     *
     * Returns 0 for $attempt <= 0 so callers don't have to special-case the
     * first delivery.
     */
    public function delayFor(int $attempt): int
    {
        if ($attempt <= 0) {
            return 0;
        }

        if ($this->strategy === self::STRATEGY_EXPONENTIAL) {
            $delay = (int) round($this->baseDelayMs * ($this->multiplier ** ($attempt - 1)));
        } else {
            $delay = $this->baseDelayMs;
        }

        if ($this->maxDelayMs > 0 && $delay > $this->maxDelayMs) {
            $delay = $this->maxDelayMs;
        }

        if ($this->jitterMs > 0) {
            $delay += random_int(0, $this->jitterMs);
        }

        return max(0, $delay);
    }

    /**
     * Serialise to a plain array — useful for logging and CLI options.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'base_delay_ms' => $this->baseDelayMs,
            'strategy' => $this->strategy,
            'max_delay_ms' => $this->maxDelayMs,
            'multiplier' => $this->multiplier,
            'jitter_ms' => $this->jitterMs,
        ];
    }
}
