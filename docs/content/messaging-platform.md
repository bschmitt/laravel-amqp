# Laravel Messaging Platform

Added in **v3.4.0** (phase 2).

This page documents the building blocks that turn `laravel-amqp` into a full microservice toolkit — competing with Symfony Messenger, MassTransit, NServiceBus, and NestJS Microservices but built around RabbitMQ and idiomatic Laravel.

| Feature | Headline |
| --- | --- |
| Service Discovery | `Rpc::service('payments')->call(...)` |
| Saga Facade | `Saga::make()->step()->compensate()` |
| Message Contract Dispatch | `OrderCreated::dispatch([...])` |
| Dead-Letter Management | `Amqp::deadLetters()->for($q)->peek()` / `summarize()` / `replayTo($t)` + `amqp:dlq` |
| `#[Retry]` Attribute | `#[Retry(attempts: 5, strategy: RetryStrategy::EXPONENTIAL)]` |
| Monitoring Dashboard | `Amqp::dashboard($queues)->snapshot()` + `amqp:monitor` |
| Causation ID Propagation | `x-causation-id` header auto-inherited |
| MessageStore | `MessageStoreInterface` + `InMemoryMessageStore` |
| Async Laravel Events | `ShouldPublishToAmqpInterface` + auto-listener |

---

## 1. Service Discovery — `Rpc::service('payments')`

Skip the `exchange` / `routing-key` / `queue` triad — register short service names and call them by alias.

### Manual registration

```php
use Bschmitt\Amqp\Facades\Rpc;
use App\Rpc\PaymentsService;

Rpc::services()->register('payments', PaymentsService::class);

$response = Rpc::service('payments')->call(
    GetPaymentRequest::make(['id' => 123])
);
```

### Auto-discovery via `alias()`

Add a `static alias()` method to any `RpcService` and pass the list to `autodiscover()`.

```php
class PaymentsService extends RpcService
{
    public static function queue(): string  { return 'rpc.payments'; }
    public static function methods(): array { return [GetPayment::class => 'find']; }
    public static function alias(): ?string { return 'payments'; }
}

Rpc::services()->autodiscover([
    PaymentsService::class,
    OrdersService::class,
]);
```

### Fluent `ServiceCaller`

```php
Rpc::service('payments')
    ->timeout(5)
    ->withProperties(['exchange' => 'rpc.payments'])
    ->call(GetPaymentRequest::make(['id' => 1]));
```

`Rpc::service()` accepts an alias **or** a service FQCN — both return the same `ServiceCaller`.

---

## 2. Saga Facade — `Saga::make()->step()->compensate()`

The existing `Saga` orchestrator now ships with a top-level `Saga` facade and a `compensate()` builder method.

```php
use Bschmitt\Amqp\Facades\Saga;

$result = Saga::make('checkout')
    ->step('reserveStock', fn($ctx) => $stock->reserve($ctx['orderId']))
        ->compensate(fn($ctx) => $stock->release($ctx['orderId']))
    ->step('chargeCard', fn($ctx) => $payments->charge($ctx['amount']))
        ->compensate(fn($ctx, $tx) => $payments->refund($tx))
    ->step('confirmOrder', fn($ctx) => $orders->confirm($ctx['orderId']))
    ->execute(['orderId' => 1, 'amount' => 49.99]);

if (!$result->succeeded()) {
    Log::error('Saga failed at ' . $result->getFailedStep(), [
        'compensated' => $result->getCompensatedSteps(),
        'reason'      => $result->getException()->getMessage(),
    ]);
}
```

Compensations run **in reverse** for every successfully completed step; the failing step is *not* compensated (its action never returned a value).

The legacy 3-argument form (`step($name, $action, $compensation)`) still works.

---

## 3. Message Contract Dispatch — `OrderCreated::dispatch(...)`

`TypedMessage` now exposes `make()` and `dispatch()` static helpers, eliminating boilerplate around `Amqp::publishTyped()`.

```php
use Bschmitt\Amqp\Support\TypedMessage;

class OrderCreated extends TypedMessage
{
    public $orderId;
    public $total;

    public static function name(): string        { return 'orders.created'; }
    public static function version(): int        { return 1; }
    public static function routingKey(): string  { return 'orders.created'; }
}

OrderCreated::dispatch(['orderId' => 'o-1', 'total' => 9.99]);

// Delayed variant
OrderCreated::dispatchLater(['orderId' => 'o-1'], 2_000); // 2s
```

Override per-call routing/properties:

```php
OrderCreated::dispatch(
    ['orderId' => 'o-1'],
    ['exchange' => 'app.events', 'correlation_id' => 'corr-1']
);
```

Outside a Laravel app, use `$amqp->publishTyped(OrderCreated::make([...]))` directly — `dispatch()` requires the container.

---

## 4. Dead-Letter Management — `Amqp::deadLetters()`

```php
use Bschmitt\Amqp\Facades\Amqp;

$dlq = Amqp::deadLetters()->for('orders.dlq');

$dlq->count();                  // 17 (fires DeadLetterDetected when > 0)
$dlq->peek(20);                 // non-destructive sample (basic_get + requeue)
$dlq->summarize(100);           // categorize by x-death reason / x-last-error
$dlq->messages(20);             // drain & return as arrays (destructive)
$dlq->replayTo('orders', 100);  // republish up to 100 to `orders` (DeadLetterReplayed)
$dlq->purge();                  // drop all DLQ messages (DeadLetterPurged)
```

| Method | Destructive? | Purpose |
|--------|--------------|---------|
| `peek($limit)` | No | Sample messages without removing them from the DLQ |
| `summarize($sampleSize)` | No | Aggregate `by_reason`, `by_origin`, `top_errors`, `oldest_failed_at` |
| `messages($limit, $requeue)` | Yes | Drain messages (ack by default) |
| `replayTo($target, $limit)` | Yes | Drain then republish to a work queue |
| `purge()` | Yes | Drop every message on the DLQ |

`messages()` returns an array of `['body', 'properties', 'headers']`. The default behaviour is to **acknowledge** each message after reading; pass `messages(20, true)` to requeue them instead.

`replayTo()` preserves `application_headers` and `content_type` from the original message, so traces and correlations survive the replay.

### CLI — `amqp:dlq`

```bash
php artisan amqp:dlq inspect orders.dlq
php artisan amqp:dlq peek    orders.dlq --limit=20 --json
php artisan amqp:dlq summary orders.dlq --limit=200
php artisan amqp:dlq replay  orders.dlq --target=orders --limit=50
php artisan amqp:dlq purge   orders.dlq --force
```

---

## 5. Declarative Retry — `#[Retry]` attribute

```php
use Bschmitt\Amqp\Attributes\Retry;
use Bschmitt\Amqp\Support\RetryStrategy;
use Bschmitt\Amqp\Support\RetryPolicy;

class CreateOrderHandler
{
    #[Retry(attempts: 5, strategy: RetryStrategy::EXPONENTIAL, delayMs: 500, jitter: true)]
    public function handle($message): void
    {
        // ...
    }
}

// In your worker bootstrap:
$policy = RetryPolicy::fromAttribute(CreateOrderHandler::class, 'handle');
$amqp->consumeWithRetry('orders', new CreateOrderHandler(), $policy);
```

| Strategy | Behaviour |
| --- | --- |
| `RetryStrategy::FIXED` | every retry waits `delayMs` |
| `RetryStrategy::EXPONENTIAL` | `delayMs * 2^n` capped at `maxDelayMs` |
| `RetryStrategy::LINEAR` | currently mapped to `FIXED` |
| `RetryStrategy::NONE` | no retries (single attempt) |

`PHP 7.x` parses the `#[...]` line as a single-line comment, so the package still loads on older runtimes — but attribute pickup only works on PHP 8+.

---

## 6. Monitoring Dashboard

```php
$snapshot = Amqp::dashboard(['orders', 'orders.dlq'])
    ->deadLetters(['orders.dlq'])
    ->lagThresholds(1000, 60.0, 300)
    ->snapshot();

[
    'process' => ['published' => 12, 'consumed' => 11, 'handled' => 10, 'failed' => 1],
    'queues' => [
        'orders' => [
            'messages' => 4,
            'lag' => 3,
            'lag_seconds' => 1.5,
            'lagging' => false,
            'publish_rate' => 3.2,
            ...
        ],
    ],
    'dead_letters' => [
        'orders.dlq' => ['messages' => 1, 'summary' => ['by_reason' => ['rejected' => 1], ...]],
    ],
    'rpc' => [
        'UserService::GetUserRequest' => ['count' => 10, 'p95_ms' => 8.0, 'error_rate' => 0.0],
    ],
    'lagging' => [], // names of queues breaching lagThresholds()
    'overview' => ['queues' => 17],
    'generated' => '2026-06-02T07:32:11+00:00',
]
```

Each queue row from `QueueMetrics::toArray()` now includes `lag`, `lag_seconds`, and `oldest_message_age_seconds` (when the Management API exposes `head_message_timestamp`).

CLI:

```bash
php artisan amqp:monitor --queue=orders --queue=orders.dlq
php artisan amqp:monitor --queue=orders --dlq=orders.dlq --rpc --json
php artisan amqp:monitor --queue=orders --lag-threshold=1000 --lag-seconds=60
# exits with code 1 when any watched queue is lagging (use in cron / CI)
```

Wire it into your HTTP layer for a Horizon-style JSON endpoint:

```php
Route::get('/admin/amqp', fn() => Amqp::dashboard([
    'orders', 'orders.dlq', 'invoices',
])->deadLetters(['orders.dlq'])->snapshot());
```

---

## 7. Causation ID Propagation

`CorrelationContext` now tracks a second identifier — the **causation id** — which represents the upstream message that caused the current one to be published.

```php
use Bschmitt\Amqp\Support\CorrelationContext;

// Inbound consumer:
CorrelationContext::inheritFromMessage($incoming); // captures message_id as causation_id

Amqp::publish('orders.shipped', $body, [
    'propagate_correlation' => true,
    'message_id' => uniqid('msg_', true),
]);
// Outbound message has:
//   correlation_id          (inherited)
//   x-correlation-id        header (inherited)
//   x-causation-id          header (set to upstream message_id)
```

The headers are W3C-friendly and interoperate cleanly with `traceparent` propagation already documented in [Production Infrastructure](./production-features.md).

---

## 8. MessageStore (audit log / event-sourcing seed)

```php
use Bschmitt\Amqp\Support\InMemoryMessageStore;
use Bschmitt\Amqp\Facades\Amqp;

$amqp = app('Amqp');
$amqp->setMessageStore(new InMemoryMessageStore());

Amqp::publish('orders.created', '{"orderId":"o-1"}');

$entries = $amqp->messageStore()->all(['direction' => 'published']);
// [ ['id' => 'msg_1_...', 'routing' => 'orders.created', 'body' => '...', ...] ]
```

Implementations must satisfy `Bschmitt\Amqp\Contracts\MessageStoreInterface`:

- `append(string $direction, string $routing, string $body, array $properties, array $headers): string`
- `find(string $id): ?array`
- `all(array $filter = [], ?int $limit = null): array`
- `count(array $filter = []): int`
- `purge(): void`

For production, back the interface with Eloquent / Redis / object storage. The default in-memory implementation is suitable for tests and short-lived CLI scripts.

---

## 9. Async Laravel Events

Mark events with `ShouldPublishToAmqpInterface` and enable the bridge — `event(new OrderCreated())` is then auto-published to RabbitMQ.

```php
use Bschmitt\Amqp\Contracts\ShouldPublishToAmqpInterface;

class OrderCreated implements ShouldPublishToAmqpInterface
{
    public function __construct(public string $orderId, public float $total) {}

    public function amqpRouting(): string  { return 'orders.created'; }
    public function amqpExchange(): string { return 'app.events'; }
    public function amqpPayload(): array   { return ['orderId' => $this->orderId, 'total' => $this->total]; }
}
```

Enable the bridge in `config/amqp.php`:

```php
return [
    // ... existing config ...
    'broadcast_laravel_events' => true,
];
```

Then:

```php
event(new OrderCreated('o-1', 9.99));
// auto-published to RabbitMQ on exchange `app.events`, routing key `orders.created`
```

Defaults when methods are omitted:

| Method | Default |
| --- | --- |
| `amqpRouting()` | snake-case event class name (`OrderCreated` → `order_created`) |
| `amqpPayload()` | JSON of every public property |
| `amqpExchange()` | broker default |

Events that **do not** implement the marker are ignored — the listener never touches them.

---

## Compatibility

All features above are additive and ship with full PHP 7.3+ syntax (verified via `scripts/check-php73-compat.php`). Attribute pickup naturally requires PHP 8+, but the attribute class itself loads on every supported runtime.
