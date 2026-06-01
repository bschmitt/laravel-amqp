# Delayed Messaging & Publish Backoff

Sometimes you want a message to sit in the broker for a while before being
delivered ("publish a reminder in 60 seconds"), or you want a publish call
itself to absorb transient broker errors. The package ships two complementary
helpers for these jobs:

| Helper | Purpose |
|--------|---------|
| `Bschmitt\Amqp\Support\DelayedPublisher` | Publishes a message that the broker holds for *N* milliseconds before delivery. |
| `Bschmitt\Amqp\Support\PublishBackoff` | Wraps any publish closure with a `RetryPolicy` so transient broker errors trigger an automatic, throttled retry of the **publish** call itself. |

Both are exposed via convenience methods on the `Amqp` facade/service.

---

## Delayed Publishing

### `publishLater($routing, $body, $delayMs, $properties = [])`

The simplest entry point — works on stock RabbitMQ out of the box:

```php
$amqp = app('Amqp');

$amqp->publishLater('orders.reminder', json_encode(['orderId' => 42]), 60000, [
    'exchange'      => 'shop.events',
    'exchange_type' => 'topic',
]);
```

The default strategy creates a per-delay TTL queue (`{routing}.delayed.{ms}`)
with `x-message-ttl` set to your delay and `x-dead-letter-exchange` /
`x-dead-letter-routing-key` pointing at your real destination. When the TTL
expires the broker forwards the message to its intended exchange/routing key.

### Two strategies

| Strategy | Constant | Requires | When to use |
|----------|----------|----------|-------------|
| `ttl` *(default)* | `DelayedPublisher::STRATEGY_TTL` | Nothing — stock RabbitMQ | Universally compatible; creates one delay queue per `{routing,delayMs}` combo. |
| `plugin` | `DelayedPublisher::STRATEGY_PLUGIN` | The [`rabbitmq-delayed-message-exchange`](https://github.com/rabbitmq/rabbitmq-delayed-message-exchange) plugin and a target exchange declared as `x-delayed-message` | Single exchange handles every delay; lower queue churn at large scale. |

```php
// Plugin strategy
$amqp->publishLater('orders.reminder', $body, 60000, [
    'exchange'        => 'shop.delayed',
    'delay_strategy'  => 'plugin',
]);
```

Pass `delay_strategy` in the standard `$properties` array — the package
extracts it before forwarding to the publisher factory.

### Naming convention

The TTL queue name follows a stable pattern so operators can find them quickly
in the RabbitMQ management UI:

```php
DelayedPublisher::delayQueueName('orders.reminder', 60000);
// => orders.reminder.delayed.60000
```

Empty routing keys are replaced with `_default` so the queue name remains
valid.

### Behaviour on the wire

For the **TTL strategy**, every `publishLater()` call:

1. Declares (idempotent) the delay queue with `x-message-ttl=delayMs`,
   `x-dead-letter-exchange=<your exchange>`, `x-dead-letter-routing-key=<your routing>`,
   and `x-expires=max(60000, delayMs*2)` so unused delay queues are garbage-collected.
2. Publishes the body to the delay queue.
3. After the TTL expires, RabbitMQ dead-letters the message to your real
   destination — your normal consumer picks it up without knowing it was
   ever delayed.

For the **plugin strategy**:

1. Publishes the body directly to the target exchange.
2. Adds an `x-delay` application header with the delay in milliseconds.
3. The plugin holds the message in memory until the delay expires, then
   routes it normally.

### Combining delays with retries

Use `publishLater()` to schedule the *initial* delivery and the existing
`DeadLetterTopology` / `RetryHandler` to handle *failures* once the message
arrives at the work queue. The two systems are orthogonal: one decides
"when to deliver", the other decides "what to do if processing fails".

### Typed + delayed

If you use the contract DTO helpers, the dedicated `publishTypedLater()`
combines serialization, schema validation, and delay scheduling in one call:

```php
$amqp->publishTypedLater(new OrderReminder('order-42'), 60000);
```

### CLI integration: `amqp:publish --delay-ms`

```bash
php artisan amqp:publish order.reminder \
    --body='{"orderId":42}' \
    --exchange=shop.events \
    --delay-ms=60000

# With the delayed-message exchange plugin
php artisan amqp:publish order.reminder \
    --body='...' \
    --delay-ms=60000 \
    --delay-strategy=plugin
```

| Option | Description |
|--------|-------------|
| `--delay-ms=` | Delay before delivery, in milliseconds. Omit for an immediate publish. |
| `--delay-strategy=` | `ttl` *(default)* or `plugin`. |

---

## Publisher-Side Backoff (`PublishBackoff`)

`RetryHandler` handles failures *inside the consumer*. `PublishBackoff`
handles failures *while publishing* — connection blips, ack timeouts,
channel-level exceptions in the middle of a batch. It wraps any publish
closure in a `RetryPolicy` and re-throws once the budget is exhausted.

### Quick start via `Amqp::withPublishBackoff()`

```php
use Bschmitt\Amqp\Support\RetryPolicy;

$amqp = app('Amqp');

$amqp->withPublishBackoff(RetryPolicy::exponential(3, 100, 2.0))
    ->run(function () use ($amqp, $payload) {
        return $amqp->publish('orders.created', $payload);
    });
```

The policy uses the same building block as `RetryHandler`, so the same
`fixed`/`exponential`/`immediate`/`none` factories apply — see
[Advanced Features → Retry & Dead-Letter Abstractions](#advanced).

### Conditional retries

By default every `Throwable` is retried. Pass a predicate to bail out on
unrecoverable errors (validation, auth, configuration mistakes):

```php
$backoff = $amqp->withPublishBackoff(
    RetryPolicy::fixed(3, 500),
    function (\Throwable $e, int $attempt) {
        // Only retry transient infra errors.
        return $e instanceof \PhpAmqpLib\Exception\AMQPConnectionClosedException
            || $e instanceof \PhpAmqpLib\Exception\AMQPTimeoutException;
    },
    function ($level, $message, $context = []) {
        Log::log($level, '[publish-backoff] '.$message, $context);
    }
);

$backoff->run(function () use ($amqp, $body) {
    return $amqp->publish('events.audit', $body);
});
```

### Where it fits

| Use case | Tool |
|----------|------|
| Reschedule a future message | `publishLater()` / `DelayedPublisher` |
| Survive transient broker errors on publish | `PublishBackoff` / `withPublishBackoff()` |
| Recover from consumer-side handler exceptions | `RetryHandler` / `consumeWithRetry()` (see [Advanced](#advanced)) |
| Long-running worker with retries baked in | `amqp:work --retry=N --retry-backoff=exponential` |

---

## Related Pages

- [Publishing Messages](#publishing) — the standard publishing API
- [Typed Messaging](#typed-messaging) — `publishTypedLater()` and contract DTOs
- [Advanced Features](#advanced) — `RetryPolicy`, `DeadLetterTopology`, `RetryHandler`
- [Artisan Commands](#artisan-commands) — `amqp:publish` and `amqp:work` flags
