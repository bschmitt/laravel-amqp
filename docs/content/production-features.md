# Production Infrastructure

This page covers exchange topology builders, queue profiles, resilient connections, connection pooling, correlation/trace propagation, and consumer lifecycle hooks — the production-oriented building blocks added in v3.5.0.

## Exchange topology builder

`ExchangeTopology` describes an exchange plus one or more queue bindings. Each binding produces a property bag consumed by `Amqp::declareExchangeTopology()`.

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Support\ExchangeTopology;
use Bschmitt\Amqp\Support\QueueProfile;

$topology = ExchangeTopology::exchange('app.events', 'topic')
    ->durable()
    ->bindQueue('billing.invoice', 'invoice.created')
    ->bindQueue('billing.payment', 'payment.received', QueueProfile::quorum());

Amqp::declareExchangeTopology($topology);
```

| Method | Purpose |
|--------|---------|
| `ExchangeTopology::exchange($name, $type)` | Start a builder (`topic`, `direct`, `fanout`, `headers`) |
| `bindQueue($queue, $routingKey = null, ?QueueProfile $profile)` | Add a queue binding (routing key defaults to queue name) |
| `declarationSteps()` | Property bags for each binding |
| `propertiesForQueue($queue)` | Properties for publish/consume on one queue |

Shortcut on the `Amqp` facade: `exchangeTopology('app.events')->bindQueue(...)`.

## Queue profiles

`QueueProfile` wraps common `queue_properties` recipes:

| Factory | Effect |
|---------|--------|
| `QueueProfile::classic()` | No extra arguments (default) |
| `QueueProfile::quorum()` | `x-queue-type = quorum` |
| `QueueProfile::priority($max)` | `x-max-priority` (1–255) |
| `QueueProfile::quorumWithPriority($max)` | Both quorum type and priority |

Use `mergeInto($properties)` to merge into a publish/consume property bag.

## Resilient connections

`ResilientConnectionManager` decorates any `ConnectionManagerInterface`:

- Retries `connect()` on transient failures (`max_reconnect_attempts`, `reconnect_delay_ms`).
- Calls `ensureConnected()` before `getChannel()` / `getConnection()`.
- Treats connections as stale when no activity occurred within `heartbeat * 2` seconds.

```php
$resilient = Amqp::resilientConnection(['use' => 'production'], [
    'max_reconnect_attempts' => 5,
    'reconnect_delay_ms' => 500,
    'heartbeat' => 30,
]);
```

## Connection pool

`Amqp::connectionPool()` returns a process-wide singleton. Cached connections are keyed by string; mark a key **persistent** to keep it open across `disconnectAll(false)`.

```php
$pool = Amqp::connectionPool();
$manager = $pool->connection('publisher', ['use' => 'production', 'resilient' => true], true);

// On worker shutdown:
$pool->disconnectAll(true);
```

Pass `resilient => true` in the config array to wrap entries with `ResilientConnectionManager`. Custom factories are supported via `$pool->setFactory(callable)`.

## Correlation ID propagation

`CorrelationContext` stores the active correlation ID (safe for one-request / one-worker PHP processes).

| API | Purpose |
|-----|---------|
| `CorrelationContext::ensure()` | Return or generate an ID |
| `CorrelationContext::set($id)` | Set explicitly |
| `CorrelationContext::applyToPublishProperties($props)` | Set `correlation_id` + `x-correlation-id` header |
| `CorrelationContext::inheritFromMessage($message)` | Read from incoming message |

Enable automatically on publish/consume:

```php
Amqp::publish('rk', $body, ['propagate_correlation' => true]);

Amqp::consumeWithLifecycle('queue', $handler, null, [
    'propagate_correlation' => true,
]);
```

## Distributed tracing (W3C)

Tracing uses `TracePropagatorInterface`. The default is `W3cTracePropagator`, which injects/extracts W3C `traceparent` (and optional `tracestate`) on `application_headers`.

```php
Amqp::publish('rk', $body, ['propagate_trace' => true]);
```

### OpenTelemetry bridge

No OpenTelemetry dependency is required. Use `CallbackTracePropagator` to delegate to your SDK:

```php
use Bschmitt\Amqp\Support\CallbackTracePropagator;

Amqp::setTracePropagator(new CallbackTracePropagator($injectFn, $extractFn));
```

`NullTracePropagator` disables tracing entirely.

## Consumer lifecycle

`ConsumerLifecycle` provides hooks and cooperative shutdown:

| Hook | When |
|------|------|
| `onStarting` | Before the consume loop |
| `onStopping` | After the loop exits |
| `onMessage` | Before each handler invocation |
| `onError` | When the handler throws |

```php
$lifecycle = (new ConsumerLifecycle())
    ->registerSignalHandlers()   // SIGINT/SIGTERM when ext-pcntl exists
    ->onStopping(function ($lifecycle) { /* cleanup */ });

Amqp::consumeWithLifecycle('jobs', $handler, $lifecycle);
```

Call `$lifecycle->requestStop()` (or send a signal) to stop processing new messages cooperatively.

## PHP compatibility

All classes in this section parse on PHP 7.3 through 8.5. Run `php scripts/check-php73-compat.php` to verify syntax locally.
