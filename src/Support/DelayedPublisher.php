<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Models\Message;
use InvalidArgumentException;

/**
 * Publish messages that should sit in the broker for some period before being
 * delivered.
 *
 * Two strategies are supported:
 *
 *  - **`STRATEGY_TTL`** (default, works on stock RabbitMQ): the message is
 *    published to a per-delay TTL queue called `{routing}.delayed.{ms}`. The
 *    delay queue is declared with `x-message-ttl` set to the requested delay
 *    and `x-dead-letter-exchange`/`x-dead-letter-routing-key` pointing at
 *    the target exchange + routing key. When the TTL expires the broker
 *    forwards the message to its intended destination. The same idea backs
 *    `AmqpQueue::laterRaw()` and {@see DeadLetterTopology}'s retry queues.
 *
 *  - **`STRATEGY_PLUGIN`**: assumes the
 *    [`rabbitmq-delayed-message-exchange`](https://github.com/rabbitmq/rabbitmq-delayed-message-exchange)
 *    plugin is loaded. The message is published directly to the target
 *    exchange (which must be declared as `x-delayed-message`) with an
 *    `x-delay` application header carrying the delay in milliseconds.
 *
 * Both strategies emit a single AMQP publish; the difference is purely how
 * the broker holds the message until the delay elapses. The plugin strategy
 * is more efficient at large scale but requires opt-in deployment; the TTL
 * strategy works everywhere.
 */
class DelayedPublisher
{
    public const STRATEGY_TTL = 'ttl';
    public const STRATEGY_PLUGIN = 'plugin';

    /** @var PublisherFactoryInterface */
    protected $publisherFactory;

    /** @var MessageFactory */
    protected $messageFactory;

    public function __construct(
        PublisherFactoryInterface $publisherFactory,
        ?MessageFactory $messageFactory = null
    ) {
        $this->publisherFactory = $publisherFactory;
        $this->messageFactory = $messageFactory ?? new MessageFactory();
    }

    /**
     * Compute the conventional delay-queue name for the TTL strategy.
     */
    public static function delayQueueName(string $routing, int $delayMs): string
    {
        if ($routing === '') {
            $routing = '_default';
        }
        return $routing.'.delayed.'.$delayMs;
    }

    /**
     * Publish `$body` so the broker delivers it after `$delayMs` milliseconds.
     *
     * Returns the result of the underlying `publish()` (true on success,
     * null/false on broker failure or unacked confirms).
     *
     * @param string                       $routing      Final routing key for the destination.
     * @param string|Message               $body         Body text or pre-built Message instance.
     * @param int                          $delayMs      Delay before delivery, in milliseconds.
     * @param array<string, mixed>         $properties   Connection / exchange / publisher properties.
     * @param string                       $strategy     {@see DelayedPublisher::STRATEGY_TTL} or {@see DelayedPublisher::STRATEGY_PLUGIN}.
     *
     * @return bool|null
     */
    public function publishLater(
        string $routing,
        $body,
        int $delayMs,
        array $properties = [],
        string $strategy = self::STRATEGY_TTL
    ) {
        if ($delayMs < 0) {
            throw new InvalidArgumentException('delayMs must be >= 0');
        }
        if (!in_array($strategy, [self::STRATEGY_TTL, self::STRATEGY_PLUGIN], true)) {
            throw new InvalidArgumentException('Unsupported delay strategy: '.$strategy);
        }

        if ($delayMs === 0) {
            // No delay → just publish straight to the destination.
            return $this->publishDirect($routing, $body, $properties);
        }

        if ($strategy === self::STRATEGY_PLUGIN) {
            return $this->publishViaPlugin($routing, $body, $delayMs, $properties);
        }

        return $this->publishViaTtlQueue($routing, $body, $delayMs, $properties);
    }

    /**
     * @param string|Message $body
     * @param array<string, mixed> $properties
     * @return bool|null
     */
    protected function publishDirect(string $routing, $body, array $properties)
    {
        $properties['routing'] = $routing;
        $publisher = $this->publisherFactory->create($properties);
        try {
            $message = $body instanceof Message
                ? $body
                : $this->messageFactory->create((string) $body, [], $this->messagePropertyKeys($properties));
            $mandatory = (bool) ($properties['mandatory'] ?? false);
            return $publisher->publish($routing, $message, $mandatory);
        } finally {
            $this->disconnect($publisher);
        }
    }

    /**
     * @param string|Message $body
     * @param array<string, mixed> $properties
     * @return bool|null
     */
    protected function publishViaPlugin(string $routing, $body, int $delayMs, array $properties)
    {
        $properties['routing'] = $routing;

        // Plugin reads x-delay from application_headers.
        $headers = (array) ($properties['application_headers'] ?? []);
        $headers['x-delay'] = $delayMs;
        $properties['application_headers'] = $headers;

        $publisher = $this->publisherFactory->create($properties);
        try {
            $messageProperties = $this->messagePropertyKeys($properties);
            $messageProperties['application_headers'] = $headers;
            $message = $body instanceof Message
                ? $body
                : $this->messageFactory->create((string) $body, $headers, $messageProperties);
            return $publisher->publish($routing, $message);
        } finally {
            $this->disconnect($publisher);
        }
    }

    /**
     * @param string|Message $body
     * @param array<string, mixed> $properties
     * @return bool|null
     */
    protected function publishViaTtlQueue(string $routing, $body, int $delayMs, array $properties)
    {
        $targetExchange = (string) ($properties['exchange'] ?? '');
        $delayQueue = self::delayQueueName($routing, $delayMs);

        $delayProperties = $properties;
        $delayProperties['queue'] = $delayQueue;
        $delayProperties['routing'] = [$delayQueue];
        $delayProperties['queue_force_declare'] = true;

        $delayProperties['queue_properties'] = array_merge(
            (array) ($properties['queue_properties'] ?? []),
            [
                'x-dead-letter-exchange' => $targetExchange,
                'x-dead-letter-routing-key' => $routing,
                'x-message-ttl' => $delayMs,
                'x-expires' => max(60000, $delayMs * 2),
            ]
        );

        $publisher = $this->publisherFactory->create($delayProperties);
        try {
            $message = $body instanceof Message
                ? $body
                : $this->messageFactory->create((string) $body, [], $this->messagePropertyKeys($properties));
            return $publisher->publish($delayQueue, $message);
        } finally {
            $this->disconnect($publisher);
        }
    }

    /**
     * Extract a subset of `$properties` that the message factory understands
     * (i.e. AMQP message properties — content_type, priority, correlation_id,
     * etc. — but not transport/queue properties).
     *
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    protected function messagePropertyKeys(array $properties): array
    {
        $allowed = [
            'priority', 'correlation_id', 'reply_to', 'message_id',
            'timestamp', 'type', 'user_id', 'app_id', 'expiration',
            'content_type', 'content_encoding', 'delivery_mode',
        ];
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $properties)) {
                $out[$key] = $properties[$key];
            }
        }
        return $out;
    }

    /**
     * @param mixed $publisher
     */
    protected function disconnect($publisher): void
    {
        if ($publisher instanceof \Bschmitt\Amqp\Core\Publisher) {
            $manager = $publisher->getConnectionManager();
            if ($manager !== null) {
                try {
                    $manager->disconnect();
                } catch (\Throwable $e) {
                    // cleanup must not propagate
                }
            }
        }
    }
}
