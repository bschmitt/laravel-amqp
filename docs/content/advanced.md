# Advanced Features

## Publisher Confirms

Enable publisher confirms for guaranteed delivery:

```php
$publisher = app('amqp.publisher');
$publisher->enablePublisherConfirms();

$publisher->setAckHandler(function ($message) {
    // Message was acknowledged
});

$publisher->setNackHandler(function ($message) {
    // Message was not acknowledged
});

$publisher->publish('routing.key', 'message');
$publisher->waitForConfirms();
```

## Consumer Prefetch (QoS)

Control message delivery rate:

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Process message
}, [
    'qos_prefetch_count' => 10,  // Max 10 unacked messages
    'qos_prefetch_size' => 0,    // No size limit
    'qos_a_global' => false,     // Per consumer
]);
```

## Queue Types

### Quorum Queue

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Process message
}, [
    'queue_properties' => [
        'x-queue-type' => 'quorum',
    ],
]);
```

### Stream Queue

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Process message
}, [
    'queue_properties' => [
        'x-queue-type' => 'stream',
    ],
    'queue_durable' => true,
]);
```

## Dead Letter Exchanges

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\\Exception $e) {
        // Reject without requeue - goes to DLQ
        $resolver->reject($message, false);
    }
}, [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx',
        'x-dead-letter-routing-key' => 'failed',
    ],
]);
```

## Advanced Retry & Dead-Letter Abstractions

The package ships three reusable building blocks that turn the above raw DLX
wiring into a declarative pipeline:

| Class | Responsibility |
|-------|----------------|
| `Bschmitt\Amqp\Support\RetryPolicy` | Value object describing the retry budget: max attempts, backoff strategy (`fixed`/`exponential`/`immediate`/`none`), optional cap and jitter. |
| `Bschmitt\Amqp\Support\DeadLetterTopology` | Builds property arrays for the work queue (with DLX to the DLQ), the DLQ itself, and per-delay retry queues. |
| `Bschmitt\Amqp\Support\RetryHandler` | Decorator that wraps any `($message, $resolver)` callable; on failure republishes to the per-delay retry queue and acks the original, or rejects to the DLQ once the budget is exhausted. |

### Declarative topology

```php
use Bschmitt\Amqp\Support\DeadLetterTopology;
use Bschmitt\Amqp\Support\RetryPolicy;

$amqp = app('Amqp');

// RetryPolicy::exponential($maxAttempts, $baseDelayMs, $multiplier, $maxDelayMs)
$policy   = RetryPolicy::exponential(5, 1000, 2.0, 60000);
$topology = DeadLetterTopology::for('orders.process', $policy)
    ->on('app.events', 'topic')
    ->withRoutingKey('orders.process')
    ->withDlqQueue('orders.process.failed');

$amqp->declareRetryTopology($topology);
```

This declares:

- the work queue `orders.process` with `x-dead-letter-exchange` set to the
  configured DLX and `x-dead-letter-routing-key` pointing at the DLQ;
- the DLQ `orders.process.failed`;
- one retry queue per unique backoff delay
  (`orders.process.retry.1000`, `.retry.2000`, `.retry.4000`, …) with a
  matching `x-message-ttl` and an `x-dead-letter-exchange` back to the work
  queue's exchange.

### Consume with auto-retry

```php
$amqp->consumeWithRetry($topology, function ($message, $resolver) {
    $payload = json_decode($message->body, true);
    processOrder($payload);
    $resolver->acknowledge($message);
});
```

`consumeWithRetry()` merges the topology's work properties into the consume
call and wraps the handler with a `RetryHandler`. On exception:

1. `x-retry-attempt` header is bumped (falling back to the broker's
   `x-death` counter on first delivery if the header is missing).
2. If the next attempt fits the policy → the message is republished to
   `{queue}.retry.{delayMs}` with the computed TTL, and the original
   delivery is acked.
3. Otherwise → the message is rejected without requeue, and RabbitMQ routes
   it to the DLQ via the work queue's DLX.

Diagnostic headers (`x-first-failed-at`, `x-last-error`) are carried across
retries so inspecting the DLQ reveals when the cascade started and what the
last failure was.

### Plain helpers

```php
use Bschmitt\Amqp\Support\RetryHandler;

$wrapped = $amqp->retryHandler($yourHandler, $topology, function ($level, $message) {
    Log::log($level, $message);
});

$amqp->consume('orders.process', $wrapped, $topology->toWorkProperties());
```

### CLI integration

`amqp:work` exposes the abstractions directly so you don't need any glue
code in your app:

```bash
php artisan amqp:work orders.process \
    --handler="App\\Messaging\\ProcessOrderHandler" \
    --retry=5 \
    --retry-backoff=exponential \
    --retry-delay=1000 \
    --retry-multiplier=2.0 \
    --retry-max-delay=60000 \
    --dlq=orders.process.failed \
    --declare-topology
```

Use `--retry=0` (or omit `--retry` entirely) to keep the old
`--requeue-on-error` behaviour without the retry pipeline.

### Choosing a policy

| Factory | When to use |
|---------|-------------|
| `RetryPolicy::fixed($maxAttempts, $delayMs, $jitterMs = 0)` | Predictable retry cadence, e.g. a third-party API that recovers within seconds. |
| `RetryPolicy::exponential($maxAttempts, $baseMs, $multiplier, $maxMs, $jitterMs)` | Most network and rate-limit failures; backs off to give downstream time to recover. |
| `RetryPolicy::immediate($maxAttempts)` | Transient races where a retry without any wait fixes the problem. |
| `RetryPolicy::none()` | Disable retries entirely — every failure goes straight to the DLQ. |

## Delayed Messaging & Publish Backoff

### `publishLater()`

Schedule a message for delivery after a delay (milliseconds):

```php
$amqp = app('Amqp');

// Default: TTL queue + dead-letter back to the target exchange (stock RabbitMQ)
$amqp->publishLater('orders.reminder', json_encode(['orderId' => 42]), 60000, [
    'exchange' => 'shop.events',
]);

// Plugin strategy: requires rabbitmq-delayed-message-exchange
$amqp->publishLater('orders.reminder', $body, 60000, [
    'exchange' => 'shop.delayed',
    'delay_strategy' => 'plugin',
]);
```

| Strategy | Constant | When to use |
|----------|----------|-------------|
| TTL + DLX | `ttl` (default) | Works everywhere; creates `{routing}.delayed.{ms}` with `x-message-ttl`. |
| Delayed exchange plugin | `plugin` | Fewer queues; exchange must be declared as `x-delayed-message`. |

`DelayedPublisher::delayQueueName($routing, $delayMs)` documents the TTL queue naming convention.

### `PublishBackoff`

Wrap any publish closure with a `RetryPolicy` to absorb transient broker errors:

```php
use Bschmitt\Amqp\Support\RetryPolicy;

$amqp->withPublishBackoff(
    RetryPolicy::exponential(3, 100, 2.0),
    function (\Throwable $e, int $attempt) {
        return $e instanceof \RuntimeException;
    }
)->run(function () use ($amqp) {
    return $amqp->publish('orders.created', $body);
});
```

Consumer-side retries use `RetryHandler`; `PublishBackoff` retries the **publish call** itself.

## Typed Message Contracts & DTO Serialization

| Type | Role |
|------|------|
| `MessageContractInterface` | `toPayload()` / `fromPayload()` contract for DTOs. |
| `TypedMessage` | Optional base class with reflection-driven defaults plus `routingKey()`, `exchange()`, `schema()` hooks. |
| `MessageSerializerInterface` | Wire format strategy (default: `JsonMessageSerializer`). |
| `Amqp::publishTyped()` / `consumeTyped()` / `publishTypedLater()` | High-level helpers that serialize, set `content_type`, and honour contract defaults. |

```php
use Bschmitt\Amqp\Support\TypedMessage;

class OrderCreated extends TypedMessage
{
    public $orderId;
    public $total;

    public function __construct($orderId = null, $total = null)
    {
        $this->orderId = $orderId;
        $this->total = $total;
    }

    public static function routingKey()
    {
        return 'orders.created';
    }
}

$amqp = app('Amqp');
$amqp->publishTyped(new OrderCreated('order-1', 19.99));

$amqp->consumeTyped('orders.queue', OrderCreated::class, function ($order, $message, $resolver) {
    process($order->orderId);
    $resolver->acknowledge($message);
});
```

Custom serializers:

```php
$amqp->setSerializer(new MyProtobufSerializer());
```

## JSON Schema Validation

When a contract defines `schema()`, typed helpers validate payloads automatically:

```php
public static function schema()
{
    return [
        'type' => 'object',
        'required' => ['orderId'],
        'properties' => [
            'orderId' => ['type' => 'string', 'minLength' => 1],
            'total'   => ['type' => 'number', 'minimum' => 0],
        ],
    ];
}
```

Failures raise `Bschmitt\Amqp\Exception\SchemaValidationException` with `errors()` returning human-readable paths (`/total: required property is missing`).

The bundled `SchemaValidator` is a **subset** of JSON Schema Draft 7 (no `$ref`, no external packages). Supported keywords include types, `required`, `properties`, `additionalProperties`, string/number/array constraints, `enum`, `const`, and `oneOf`/`anyOf`/`allOf`/`not`.

Standalone validation:

```php
$errors = app('Amqp')->schemaValidator()->validate($payload, OrderCreated::schema());
```

CLI: `php artisan amqp:work orders --handler=... --contract="App\\Messaging\\OrderCreated" --validate-schema`

## Message Priority

```php
// Configure queue with priority support
$amqp = app('Amqp');
$amqp->consume('priority-queue', function ($message, $resolver) {
    // Process message
}, [
    'queue_properties' => [
        'x-max-priority' => 10,
    ],
]);

// Publish with priority
Amqp::publish('routing.key', 'high priority', [
    'priority' => 10,
]);
```

## Lazy Queues

```php
$amqp = app('Amqp');
$amqp->consume('lazy-queue', function ($message, $resolver) {
    // Process message
}, [
    'queue_properties' => [
        'x-queue-mode' => 'lazy',
    ],
]);
```

## Production Infrastructure (v3.4+)

Exchange topology builders, quorum/priority `QueueProfile` presets, resilient
connections, connection pooling, W3C trace propagation, correlation context,
and consumer lifecycle hooks are documented on the dedicated
[Production Infrastructure](./production-features.md) page.
