<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\PublishBackoff;
use Bschmitt\Amqp\Support\RetryPolicy;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use RuntimeException;

/**
 * Unit coverage for {@see PublishBackoff}.
 *
 * The helper composes a {@see RetryPolicy} with a synchronous publish
 * closure; tests inject a fake sleeper so the suite stays fast and
 * deterministic. We verify success-on-Nth-attempt, exhaustion behaviour,
 * conditional retry predicates, and that delay arithmetic flows from the
 * policy untouched.
 */
class PublishBackoffTest extends BaseTestCase
{
    public function testReturnsImmediatelyWhenPublishSucceeds(): void
    {
        $sleeps = [];
        $backoff = new PublishBackoff(
            RetryPolicy::fixed(3, 100),
            null,
            null,
            function ($us) use (&$sleeps) { $sleeps[] = $us; }
        );

        $invocations = 0;
        $result = $backoff->run(function () use (&$invocations) {
            $invocations++;
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $invocations);
        $this->assertSame([], $sleeps);
    }

    public function testRetriesUntilSuccessAndSleepsBetweenAttempts(): void
    {
        $sleeps = [];
        $backoff = new PublishBackoff(
            RetryPolicy::exponential(5, 100, 2.0),
            null,
            null,
            function ($us) use (&$sleeps) { $sleeps[] = $us; }
        );

        $attempts = 0;
        $result = $backoff->run(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new RuntimeException('transient #'.$attempts);
            }
            return 'finally';
        });

        $this->assertSame('finally', $result);
        $this->assertSame(3, $attempts);
        // After attempt 1 fails, sleep delayFor(1) = 100 ms; after 2 fails, delayFor(2) = 200 ms.
        $this->assertSame([100 * 1000, 200 * 1000], $sleeps);
    }

    public function testThrowsLastExceptionAfterBudgetExhausted(): void
    {
        $backoff = new PublishBackoff(
            RetryPolicy::fixed(2, 1),
            null,
            null,
            function ($us) { /* no-op */ }
        );

        $attempts = 0;
        try {
            $backoff->run(function () use (&$attempts) {
                $attempts++;
                throw new RuntimeException('boom #'.$attempts);
            });
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            // initial + 2 retries = 3 invocations
            $this->assertSame(3, $attempts);
            $this->assertSame('boom #3', $e->getMessage());
        }
    }

    public function testShouldRetryPredicateCanStopEarly(): void
    {
        $backoff = new PublishBackoff(
            RetryPolicy::fixed(5, 1),
            function (\Throwable $error, int $attempt) {
                // Only retry "transient" errors; bail on others immediately.
                return strpos($error->getMessage(), 'transient') === 0;
            },
            null,
            function ($us) { /* no-op */ }
        );

        $attempts = 0;
        try {
            $backoff->run(function () use (&$attempts) {
                $attempts++;
                throw new RuntimeException('fatal failure');
            });
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame(1, $attempts, 'predicate must stop after first failure');
            $this->assertSame('fatal failure', $e->getMessage());
        }
    }

    public function testLoggerSeesWarningPerRetryAndErrorOnExhaustion(): void
    {
        $events = [];
        $backoff = new PublishBackoff(
            RetryPolicy::fixed(2, 0),
            null,
            function ($level, $message, $context = []) use (&$events) {
                $events[] = ['level' => $level, 'message' => $message];
            },
            function ($us) { /* no-op */ }
        );

        try {
            $backoff->run(function () {
                throw new RuntimeException('still failing');
            });
        } catch (RuntimeException $e) {
            // expected
        }

        $levels = array_column($events, 'level');
        $this->assertSame(['warning', 'warning', 'error'], $levels);
    }

    public function testZeroDelayPolicySkipsSleep(): void
    {
        $sleeps = [];
        $backoff = new PublishBackoff(
            RetryPolicy::immediate(2),
            null,
            null,
            function ($us) use (&$sleeps) { $sleeps[] = $us; }
        );

        try {
            $backoff->run(function () { throw new RuntimeException('x'); });
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame([], $sleeps, 'no sleeps for zero-delay policy');
    }

    public function testPolicyAccessorReturnsConfiguredPolicy(): void
    {
        $policy = RetryPolicy::fixed(1, 5);
        $backoff = new PublishBackoff($policy);
        $this->assertSame($policy, $backoff->policy());
    }
}
