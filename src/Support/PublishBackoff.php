<?php

namespace Bschmitt\Amqp\Support;

use Throwable;

/**
 * Publisher-side counterpart to {@see RetryHandler}.
 *
 * Wraps a publishing closure with a {@see RetryPolicy} so transient broker
 * errors (connection blips, ack timeouts, channel-level exceptions) don't
 * immediately propagate to the caller.
 *
 * Use cases:
 *  - HTTP request handlers that publish a fire-and-forget event before
 *    returning a response; you want to absorb a single bad TCP frame.
 *  - Batch importers that publish thousands of messages where one failure
 *    out of a thousand is recoverable but `Worker died` is not.
 *
 * The helper is intentionally synchronous: it calls `usleep()` between
 * attempts so it composes with any synchronous publisher (this package's
 * {@see \Bschmitt\Amqp\Core\Publisher}, but also userland custom publishers).
 *
 * Returning the policy's `RetryPolicy::shouldRetry($attempt)` result is what
 * keeps the loop honest — once the retry budget is exhausted the original
 * exception is re-thrown so the caller can decide what to do (often the
 * answer is "log and move on"; sometimes it is "kill the process").
 */
class PublishBackoff
{
    /** @var RetryPolicy */
    protected $policy;

    /** @var callable|null */
    protected $shouldRetry;

    /** @var callable|null */
    protected $logger;

    /** @var callable|null */
    protected $sleeper;

    /**
     * @param RetryPolicy   $policy       Backoff / max-attempts configuration.
     * @param callable|null $shouldRetry  Optional `fn(Throwable, int $attempt): bool` predicate.
     *                                    Defaults to "retry every Throwable".
     * @param callable|null $logger       Optional `fn(string $level, string $message, array $context): void`.
     * @param callable|null $sleeper      Override the sleeper used between attempts
     *                                    (`fn(int $microseconds): void`). Useful for tests.
     */
    public function __construct(
        RetryPolicy $policy,
        ?callable $shouldRetry = null,
        ?callable $logger = null,
        ?callable $sleeper = null
    ) {
        $this->policy = $policy;
        $this->shouldRetry = $shouldRetry;
        $this->logger = $logger;
        $this->sleeper = $sleeper;
    }

    public function policy(): RetryPolicy
    {
        return $this->policy;
    }

    /**
     * Run `$publish` until it succeeds or the policy is exhausted.
     *
     * Returns whatever `$publish` returns on success. Re-throws the last
     * caught exception on exhaustion.
     *
     * @param callable $publish A callable that performs the actual publish.
     *                          It MUST throw on failure.
     *
     * @return mixed
     * @throws Throwable
     */
    public function run(callable $publish)
    {
        $attempt = 0;
        // We keep the *first* exception so the rethrown one carries the
        // original stack trace, but log every one in between.
        $lastError = null;

        while (true) {
            try {
                return $publish();
            } catch (Throwable $error) {
                $attempt++;
                $lastError = $error;

                $shouldRetry = $this->shouldRetry === null
                    ? true
                    : (bool) ($this->shouldRetry)($error, $attempt);

                if (!$shouldRetry || !$this->policy->shouldRetry($attempt)) {
                    $this->log('error', sprintf(
                        'Publish failed after %d attempt(s); giving up: %s',
                        $attempt,
                        $error->getMessage()
                    ));
                    throw $error;
                }

                $delayMs = $this->policy->delayFor($attempt);
                $this->log('warning', sprintf(
                    'Publish failed (attempt %d/%d), retrying after %dms: %s',
                    $attempt,
                    $this->policy->maxAttempts(),
                    $delayMs,
                    $error->getMessage()
                ));

                if ($delayMs > 0) {
                    $this->sleep($delayMs);
                }
            }
        }

        // unreachable — silence static analyzers
        throw $lastError;
    }

    /**
     * Sleep helper. Uses the injected sleeper when present so unit tests
     * can swap in a counter without burning real wall time.
     */
    protected function sleep(int $delayMs): void
    {
        $microseconds = $delayMs * 1000;
        if ($this->sleeper !== null) {
            ($this->sleeper)($microseconds);
            return;
        }
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }

    /**
     * @param string                $level
     * @param string                $message
     * @param array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }
}
