<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Factories\MessageFactory;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Wraps a user-supplied message handler with declarative retry + dead-letter
 * behaviour driven by a {@see DeadLetterTopology} and {@see RetryPolicy}.
 *
 * When the inner handler throws, the wrapper:
 *
 *   - increments an `x-retry-attempt` header to count failed deliveries;
 *   - republishes the message to the per-delay retry queue defined by the
 *     topology (TTL set on the queue routes it back to the work queue);
 *   - acknowledges the original delivery to release the broker.
 *
 * Once the retry budget is exhausted the wrapper rejects the message without
 * requeue, allowing RabbitMQ to route it to the DLX/DLQ configured on the
 * work queue.
 *
 * The handler is intentionally agnostic about *how* the inner callable shapes
 * its work — any `($message, $resolver)` invokable works (closure, invokable
 * class, or {@see \Bschmitt\Amqp\Contracts\MessageHandlerInterface}).
 */
class RetryHandler
{
    /** Header that tracks the number of failed attempts so far. */
    public const ATTEMPT_HEADER = 'x-retry-attempt';

    /** ISO-8601 timestamp of the first failure (carried across retries). */
    public const FIRST_FAILED_AT_HEADER = 'x-first-failed-at';

    /** Truncated message of the most recent exception. */
    public const LAST_ERROR_HEADER = 'x-last-error';

    /** @var callable */
    protected $handler;

    /** @var DeadLetterTopology */
    protected $topology;

    /** @var PublisherFactoryInterface */
    protected $publisherFactory;

    /** @var MessageFactory */
    protected $messageFactory;

    /** @var callable|null */
    protected $logger;

    /**
     * @param callable                  $handler          Inner handler: fn(AMQPMessage, ConsumerInterface): void
     * @param DeadLetterTopology        $topology         Topology describing work / retry / DLQ queues.
     * @param PublisherFactoryInterface $publisherFactory Factory used to publish retry messages.
     * @param MessageFactory|null       $messageFactory   Optional override for the message factory.
     * @param callable|null             $logger           Optional logger: fn(string $level, string $message, array $context): void
     */
    public function __construct(
        callable $handler,
        DeadLetterTopology $topology,
        PublisherFactoryInterface $publisherFactory,
        ?MessageFactory $messageFactory = null,
        ?callable $logger = null
    ) {
        $this->handler = $handler;
        $this->topology = $topology;
        $this->publisherFactory = $publisherFactory;
        $this->messageFactory = $messageFactory ?? new MessageFactory();
        $this->logger = $logger;
    }

    /**
     * Convenience factory.
     */
    public static function wrap(
        callable $handler,
        DeadLetterTopology $topology,
        PublisherFactoryInterface $publisherFactory,
        ?MessageFactory $messageFactory = null,
        ?callable $logger = null
    ): self {
        return new self($handler, $topology, $publisherFactory, $messageFactory, $logger);
    }

    public function getTopology(): DeadLetterTopology
    {
        return $this->topology;
    }

    public function __invoke(AMQPMessage $message, ConsumerInterface $resolver): void
    {
        $this->handle($message, $resolver);
    }

    /**
     * Run the inner handler and apply retry/DLQ semantics on failure.
     */
    public function handle(AMQPMessage $message, ConsumerInterface $resolver): void
    {
        try {
            ($this->handler)($message, $resolver);
        } catch (Throwable $exception) {
            $this->handleFailure($message, $resolver, $exception);
        }
    }

    /**
     * Number of failed attempts already recorded for this delivery.
     *
     * Prefers the package's own `x-retry-attempt` header; falls back to
     * RabbitMQ's `x-death` accounting which is added automatically when
     * messages traverse dead-letter queues.
     */
    public function currentAttempt(AMQPMessage $message): int
    {
        $headers = $this->extractHeaders($message);

        if (isset($headers[self::ATTEMPT_HEADER])) {
            return max(0, (int) $headers[self::ATTEMPT_HEADER]);
        }

        if (isset($headers['x-death']) && is_array($headers['x-death'])) {
            $total = 0;
            foreach ($headers['x-death'] as $entry) {
                if (is_array($entry) && isset($entry['count'])) {
                    $total += (int) $entry['count'];
                }
            }
            return $total;
        }

        return 0;
    }

    /**
     * @param AMQPMessage      $message
     * @param ConsumerInterface $resolver
     * @param Throwable         $exception
     */
    protected function handleFailure(AMQPMessage $message, ConsumerInterface $resolver, Throwable $exception): void
    {
        $attempt = $this->currentAttempt($message);
        $next = $attempt + 1;
        $policy = $this->topology->getRetryPolicy();

        if ($policy->shouldRetry($next)) {
            $delayMs = $policy->delayFor($next);

            try {
                $this->republish($message, $next, $delayMs, $exception);
            } catch (Throwable $republishError) {
                $this->log('error', sprintf(
                    'Retry republish failed (attempt %d/%d): %s',
                    $next,
                    $policy->maxAttempts(),
                    $republishError->getMessage()
                ), ['original' => $exception->getMessage()]);

                $resolver->reject($message, false);
                return;
            }

            $this->log('warning', sprintf(
                'Retrying message (attempt %d/%d after %dms): %s',
                $next,
                $policy->maxAttempts(),
                $delayMs,
                $exception->getMessage()
            ));

            $resolver->acknowledge($message);
            return;
        }

        $this->log('error', sprintf(
            'Retries exhausted (%d attempts); dead-lettering: %s',
            $attempt,
            $exception->getMessage()
        ));
        $resolver->reject($message, false);
    }

    /**
     * Publish the message to the per-delay retry queue with updated headers.
     *
     * @param AMQPMessage $message
     * @param int         $attempt   The 1-based retry attempt number.
     * @param int         $delayMs   Computed delay before re-delivery.
     * @param Throwable   $exception The error that triggered the retry.
     */
    protected function republish(AMQPMessage $message, int $attempt, int $delayMs, Throwable $exception): void
    {
        $properties = $this->topology->toRetryQueueProperties($delayMs);
        $publisher = $this->publisherFactory->create($properties);

        try {
            $appHeaders = $this->extractHeaders($message);
            $appHeaders[self::ATTEMPT_HEADER] = $attempt;
            if (empty($appHeaders[self::FIRST_FAILED_AT_HEADER])) {
                $appHeaders[self::FIRST_FAILED_AT_HEADER] = gmdate('c');
            }
            $appHeaders[self::LAST_ERROR_HEADER] = $this->truncate($exception->getMessage(), 1024);
            unset($appHeaders['x-death']);

            $copiedProperties = $this->copyableProperties($message);
            $copiedProperties['application_headers'] = $appHeaders;

            $replay = $this->messageFactory->create($message->body, [], $copiedProperties);
            $routingKey = $this->topology->getRetryQueueName($delayMs);

            $publisher->publish($routingKey, $replay);
        } finally {
            $this->disconnectPublisher($publisher);
        }
    }

    /**
     * Extract a plain-array view of the application headers (if any).
     *
     * @return array<string, mixed>
     */
    protected function extractHeaders(AMQPMessage $message): array
    {
        if (!$message->has('application_headers')) {
            return [];
        }
        $headers = $message->get('application_headers');

        if ($headers instanceof AMQPTable) {
            return $headers->getNativeData();
        }
        if (is_array($headers)) {
            return $headers;
        }
        return [];
    }

    /**
     * Subset of standard AMQP message properties safe to carry over to a
     * replayed message.
     *
     * @return array<string, mixed>
     */
    protected function copyableProperties(AMQPMessage $message): array
    {
        $whitelist = [
            'content_type', 'content_encoding', 'delivery_mode', 'priority',
            'correlation_id', 'reply_to', 'expiration', 'message_id',
            'timestamp', 'type', 'user_id', 'app_id', 'cluster_id',
        ];

        $copied = [];
        foreach ($whitelist as $key) {
            if ($message->has($key)) {
                $copied[$key] = $message->get($key);
            }
        }
        return $copied;
    }

    /**
     * Best-effort disconnect after publishing the retry message.
     *
     * @param mixed $publisher
     */
    protected function disconnectPublisher($publisher): void
    {
        if ($publisher instanceof \Bschmitt\Amqp\Core\Publisher) {
            $manager = $publisher->getConnectionManager();
            if ($manager !== null) {
                try {
                    $manager->disconnect();
                } catch (Throwable $e) {
                    // ignored: cleanup must not interfere with retry semantics
                }
            }
        }
    }

    protected function truncate(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }
        return substr($value, 0, $maxLength - 3).'...';
    }

    /**
     * @param string                $level   psr/log style level
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
