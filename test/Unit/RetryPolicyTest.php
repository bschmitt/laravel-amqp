<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\RetryPolicy;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use InvalidArgumentException;

/**
 * Unit coverage for {@see RetryPolicy}.
 *
 * Pure value-object — every test runs without the broker. We focus on the
 * arithmetic of the strategies (so a future regression in `delayFor()` is
 * caught) and on the validation rules that keep callers honest.
 */
class RetryPolicyTest extends BaseTestCase
{
    public function testFixedFactoryReturnsConstantDelay(): void
    {
        $policy = RetryPolicy::fixed(3, 500);

        $this->assertSame(3, $policy->maxAttempts());
        $this->assertSame(RetryPolicy::STRATEGY_FIXED, $policy->strategy());
        $this->assertSame(500, $policy->delayFor(1));
        $this->assertSame(500, $policy->delayFor(2));
        $this->assertSame(500, $policy->delayFor(3));
    }

    public function testExponentialFactoryGrowsGeometrically(): void
    {
        $policy = RetryPolicy::exponential(4, 100, 2.0);

        $this->assertSame(100, $policy->delayFor(1));
        $this->assertSame(200, $policy->delayFor(2));
        $this->assertSame(400, $policy->delayFor(3));
        $this->assertSame(800, $policy->delayFor(4));
    }

    public function testExponentialBackoffRespectsMaxDelayCap(): void
    {
        $policy = RetryPolicy::exponential(6, 100, 3.0, 500);

        $this->assertSame(100, $policy->delayFor(1));
        $this->assertSame(300, $policy->delayFor(2));
        $this->assertSame(500, $policy->delayFor(3), 'should clamp at maxDelayMs');
        $this->assertSame(500, $policy->delayFor(5));
    }

    public function testImmediateFactoryProducesZeroDelays(): void
    {
        $policy = RetryPolicy::immediate(2);

        $this->assertSame(2, $policy->maxAttempts());
        $this->assertSame(0, $policy->delayFor(1));
        $this->assertSame(0, $policy->delayFor(2));
    }

    public function testNoneFactoryDisablesRetries(): void
    {
        $policy = RetryPolicy::none();

        $this->assertSame(0, $policy->maxAttempts());
        $this->assertFalse($policy->shouldRetry(1));
        $this->assertFalse($policy->shouldRetry(10));
    }

    public function testShouldRetryRespectsMaxAttempts(): void
    {
        $policy = RetryPolicy::fixed(3, 1);

        $this->assertTrue($policy->shouldRetry(1));
        $this->assertTrue($policy->shouldRetry(3));
        $this->assertFalse($policy->shouldRetry(4));
    }

    public function testDelayForZeroOrNegativeAttemptIsZero(): void
    {
        $policy = RetryPolicy::exponential(5, 1000, 2.0);

        $this->assertSame(0, $policy->delayFor(0));
        $this->assertSame(0, $policy->delayFor(-1));
    }

    public function testJitterAddsBoundedRandomness(): void
    {
        $policy = RetryPolicy::fixed(3, 100, 50);

        for ($i = 0; $i < 20; $i++) {
            $delay = $policy->delayFor(1);
            $this->assertGreaterThanOrEqual(100, $delay);
            $this->assertLessThanOrEqual(150, $delay);
        }
    }

    public function testNegativeMaxAttemptsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryPolicy(-1);
    }

    public function testInvalidStrategyRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryPolicy(1, 100, 'invalid-strategy');
    }

    public function testNegativeJitterRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryPolicy::fixed(1, 1, -5);
    }

    public function testMultiplierBelowOneRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryPolicy::exponential(1, 100, 0.5);
    }

    public function testToArrayExposesFullConfiguration(): void
    {
        $policy = RetryPolicy::exponential(5, 200, 1.5, 5000, 25);
        $serialised = $policy->toArray();

        $this->assertSame([
            'max_attempts' => 5,
            'base_delay_ms' => 200,
            'strategy' => RetryPolicy::STRATEGY_EXPONENTIAL,
            'max_delay_ms' => 5000,
            'multiplier' => 1.5,
            'jitter_ms' => 25,
        ], $serialised);
    }
}
