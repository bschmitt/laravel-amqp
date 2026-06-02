<?php

namespace Bschmitt\Amqp\Support;

/**
 * Retry strategy identifiers consumable by {@see RetryPolicy} and the
 * {@see \Bschmitt\Amqp\Attributes\Retry} attribute.
 *
 * Implemented as a class with `const` rather than `enum` so the package
 * remains compatible with PHP 7.3+. On PHP 8.1+ callers may still pass
 * their own native enum via `value` semantics.
 */
final class RetryStrategy
{
    /** Equal delay between every attempt (`delayMs`). */
    const FIXED = 'fixed';

    /** Exponential back-off (`delayMs * 2^n` capped at `maxDelayMs`). */
    const EXPONENTIAL = 'exponential';

    /** Equal-step increment per attempt (`delayMs * n`). */
    const LINEAR = 'linear';

    /** Disable retries entirely (single attempt). */
    const NONE = 'none';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [self::FIXED, self::EXPONENTIAL, self::LINEAR, self::NONE];
    }

    /**
     * @param string $value
     * @return bool
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /** @codeCoverageIgnore */
    private function __construct() {}
}
