# Scale & Interop

Added in v3.4.0 — RPC helpers, polyglot envelopes, observability metrics, and throughput-tuned workers.

## RPC abstraction

### RpcClient

```php
$result = Amqp::rpcClient(['exchange' => 'rpc'])
    ->asJson()
    ->timeout(15)
    ->call('inventory.reserve', ['sku' => 'ABC', 'qty' => 2]);
```

| Method | Purpose |
|--------|---------|
| `asJson()` | JSON-encode requests / decode responses |
| `timeout($seconds)` | Default RPC timeout |
| `call($routing, $request, $properties, $timeout)` | Returns `RpcCallResult` |

`RpcCallResult`: `succeeded()`, `failed()`, `timedOut()`, `body()`, `correlationId()`, `durationMs()`, `errorClass()`.

Each call is timed and recorded in `Amqp::rpcMetrics()` (see **RPC latency** below).

Built on the existing `Amqp::rpc()` primitive (exclusive reply queue + correlation ID).

### RpcServer

```php
Amqp::rpcServer()->asJson()->serve('rpc.inventory', function ($request, $consumer) {
    return ['reserved' => true];
});
```

The handler receives the decoded request (when `asJson()` is enabled) and the active `Consumer` instance for advanced `reply()` usage. Failed handlers optionally return JSON error payloads (`sendErrors(true)` by default).

## Cross-service / polyglot messaging

`InteropEnvelope` stamps portable application headers:

| Header | Purpose |
|--------|---------|
| `x-message-type` | Logical event name |
| `x-schema-version` | Contract version |
| `x-source-service` | Publishing service identifier |
| `content_type` / `type` | MIME type and AMQP type property |

```php
Amqp::publishInterop($routing, $payload, $messageType, $sourceService, $properties, $schemaVersion);

Amqp::consumeInterop($queue, function (InteropMessage $interop, $message, $resolver) {
    $data = InteropEnvelope::decodePayload($interop);
});
```

Non-PHP consumers read the same headers without shared PHP classes.

## Observability & queue metrics

### MetricsCollector

`Amqp::metrics()` returns in-process counters:

- `published`, `consumed`, `handled`, `failed`
- `published_by_routing`, `consumed_by_queue`

Incremented automatically on `publish()`, `consumeWithMiddleware()`, and `HighPerformanceWorker`.

Export via `snapshot()` to logs, Prometheus, or StatsD.

### QueueMetrics

`Amqp::queueMetrics($queue, $vhost)` wraps the Management API response:

- `messageCount()`, `messagesReady()`, `messagesUnacknowledged()`
- `consumerCount()`, `publishRate()`, `deliverRate()`
- `lag()` — `messages_ready + messages_unacknowledged` (consumer backlog)
- `lagSeconds()` — estimated time-to-drain at the current deliver rate
- `oldestMessageAgeSeconds()` — head-of-queue age when `head_message_timestamp` is present
- `isLagging($maxBacklog, $maxLagSeconds, $maxAgeSeconds)` — threshold helper for alerts
- `toArray()` — includes `lag`, `lag_seconds`, and `oldest_message_age_seconds`

`getQueueStats()` remains available as a raw-array alias.

### RPC latency (`RpcLatencyRecorder`)

`Amqp::rpcMetrics()` aggregates per-key latencies (routing keys for `RpcClient`, `Service::Request` for gRPC-lite):

```php
Amqp::rpcClient()->call('inventory.reserve', ['sku' => 'A']);
Rpc::call(UserService::class, GetUserRequest::make(['id' => 1]));

$stats = Amqp::rpcMetrics()->snapshot();
// ['inventory.reserve' => ['count' => 10, 'p95_ms' => 4.0, 'error_rate' => 0.0, ...]]
```

Included automatically in `Amqp::dashboard(...)->snapshot()['rpc']` and via `php artisan amqp:monitor --rpc`.

## High-performance workers

### WorkerOptions presets

| Factory | Prefetch | Use case |
|---------|----------|----------|
| `WorkerOptions::throughput($n)` | 50 (default) | Batch processing |
| `WorkerOptions::lowLatency()` | 1 | RPC / low-latency |

`mergeInto($properties)` sets `qos`, `qos_prefetch_count`, and optional `__worker_persistent_pool`.

### HighPerformanceWorker

```php
Amqp::highPerformanceWorker(WorkerOptions::throughput(100))
    ->run('jobs', $handler, $properties, $lifecycle, $middlewares);
```

Shortcut: `Amqp::consumeOptimized($queue, $handler, $properties, $options)`.

### Artisan

```bash
php artisan amqp:work orders --handler="App\\Handlers\\OrderHandler" --optimized
```

Applies `WorkerOptions::throughput(50)` when `--prefetch-count` is not set.

## PHP compatibility

All classes parse on PHP 7.3 through 8.5.
