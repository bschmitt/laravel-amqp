# Release Notes

---

## Version 3.4.2 - Patch Release

Observability patch on top of `3.4.1`: consumer lag metrics, DLQ
inspection/summarization, RPC latency histograms, and two new Artisan
commands. Fully backwards-compatible — no migration required.

### Compatibility

- **PHP**: 7.3 through 8.5
- **Laravel**: 8.x through 13.x (Lumen 8.x+)
- Every new file under `src/` parses as PHP 7.3 (verified via
  `scripts/check-php73-compat.php`).

### Consumer lag monitoring

- `QueueMetrics::lag()`, `lagSeconds()`, `oldestMessageAgeSeconds()`, `isLagging()`
- `lag`, `lag_seconds`, `oldest_message_age_seconds` in `QueueMetrics::toArray()`
- `MonitoringDashboard::lagThresholds()` + `lagging` key in snapshots
- `php artisan amqp:monitor --lag-threshold` / `--lag-seconds` / `--lag-age` (exit `1` when breaching)

### Dead-letter queue monitoring

- `DeadLetterManager::peek()` — non-destructive `basic_get` + requeue
- `DeadLetterManager::summarize()` — group by `x-death` reason, origin, `x-last-error`
- `Amqp::peekQueue()` low-level helper
- Events: `DeadLetterDetected`, `DeadLetterReplayed`, `DeadLetterPurged`
- `php artisan amqp:dlq {inspect|peek|summary|replay|purge}`
- `MonitoringDashboard::deadLetters()` + `dead_letters` block in snapshots

### RPC latency tracking

- `RpcLatencyRecorder` + `Amqp::rpcMetrics()` with p50/p95/p99 estimates
- `RpcCallResult::durationMs()` / `failed()` / `errorClass()`
- `RpcClient::call()` and `RpcDispatcher::call()` record timings automatically
- Server handler timings under `{Service}::{Request}:serve`
- Events: `RpcCallStarted`, `RpcCallCompleted`, `RpcCallFailed`
- `php artisan amqp:monitor --rpc`

### Tests

- Extended: `QueueMetricsTest`, `DeadLetterManagerTest`, `MonitoringDashboardTest`,
  `RpcClientTest`, `RpcDispatcherTest`
- New: `RpcLatencyRecorderTest`
- Unit suite additions: **49 tests / 113 assertions** in the touched files above

### Migration

No migration required. All features are opt-in.

---

## Version 3.4.0 - Minor Release

A consolidated release that lands the original twenty roadmap features plus
nine "messaging-platform" additions (service discovery, sagas-as-a-facade,
typed-message dispatch, DLQ management, retry attribute, monitoring dashboard,
causation IDs, MessageStore, async Laravel events) — all fully
backwards-compatible and verified on PHP 7.3 through 8.5.

No migration required. Every new feature is opt-in; existing publish/consume
code, handler signatures, and config layouts continue to work unchanged.

### Compatibility

- **PHP**: 7.3 through 8.5
- **Laravel**: 8.x through 13.x (Lumen 8.x+)
- **PHPUnit**: `^9.6` on PHP 7.3/7.4; `^10.5|^11.5|^12.0` on PHP 8.0+
- New `scripts/check-php73-compat.php` (curated) and
  `scripts/check-php73-compat-all-src.php` (entire `src/`) using
  `nikic/php-parser` — every file under `src/` parses as PHP 7.3.

### Retry, Delayed, Typed & Schema Messaging

1. **Advanced retry & dead-letter abstractions**
   - `Bschmitt\Amqp\Support\RetryPolicy` value object with
     `fixed()` / `exponential()` / `immediate()` / `none()` factories and
     configurable `maxDelayMs` cap + `jitterMs`.
   - `DeadLetterTopology` builder generates property bags for the work
     queue, DLQ, and per-delay retry queues (`{queue}.retry.{ms}`).
   - `RetryHandler` decorator wraps any callable with the full
     republish-or-reject pipeline (tracks `x-retry-attempt`,
     `x-first-failed-at`, and `x-last-error` headers).
   - `Amqp::declareRetryTopology()`, `retryHandler()`,
     `consumeWithRetry()`, `topology()`.
   - `amqp:work` gains `--retry`, `--retry-backoff`, `--retry-delay`,
     `--retry-multiplier`, `--retry-max-delay`, `--retry-jitter`,
     `--dlq`, and `--declare-topology`.

2. **Delayed messaging & publisher backoff**
   - `Bschmitt\Amqp\Support\DelayedPublisher` with two strategies:
     `ttl` (default, TTL+DLX per-delay queue — works on stock RabbitMQ)
     and `plugin` (`rabbitmq-delayed-message-exchange`).
   - `Amqp::publishLater()`, `publishTypedLater()`,
     `delayedPublisher()`.
   - `PublishBackoff` wraps any publish closure with a `RetryPolicy`,
     exposed via `Amqp::withPublishBackoff()`.
   - `amqp:publish` gains `--delay-ms` and `--delay-strategy=ttl|plugin`.

3. **Typed message contracts & DTO serialization**
   - `Bschmitt\Amqp\Contracts\MessageContractInterface` and the
     optional `TypedMessage` base class (reflection-driven defaults plus
     `routingKey()`, `exchange()`, `schema()` hooks).
   - `MessageSerializerInterface` strategy; default is
     `JsonMessageSerializer` (`JSON_THROW_ON_ERROR`, unicode/slash-safe).
   - `Amqp::publishTyped()`, `publishTypedLater()`, `consumeTyped()`,
     `setSerializer()`, `getSerializer()`.
   - `amqp:work --contract=` deserializes inbound bodies and passes the
     DTO as a third handler argument (the existing two-argument signature
     keeps working — the new arg defaults to `null`).

4. **JSON Schema validation for messages**
   - Zero-dependency `Bschmitt\Amqp\Support\SchemaValidator`
     implementing a Draft 7 subset (types, `required`, `properties`,
     `additionalProperties`, string/number/array constraints, `enum`,
     `const`, `oneOf`/`anyOf`/`allOf`/`not`, common `format`s).
   - `SchemaValidationException` carries `errors()` with JSON-pointer
     paths.
   - Schema validation runs automatically on publish/consume whenever a
     contract exposes a non-null `schema()`.
   - `amqp:work --validate-schema` enforces in long-running workers.

### Production Infrastructure

5. **Exchange & topology builders**
   - `ExchangeTopology` fluent builder for exchange + multi-queue bindings.
   - `Amqp::declareExchangeTopology()`, `exchangeTopology()` shortcut.

6. **Quorum & priority queue profiles**
   - `QueueProfile` presets: `classic()`, `quorum()`, `priority()`,
     `quorumWithPriority()` with `mergeInto()` for property bags.

7. **Auto reconnect & heartbeat monitoring**
   - `ResilientConnectionManager` decorator with connect retries and
     heartbeat staleness detection.
   - `Amqp::resilientConnection()` factory helper.

8. **Connection pooling & persistent channels**
   - `ConnectionPool` singleton via `Amqp::connectionPool()` with
     persistent key support and optional resilient wrapping.

9. **Distributed tracing (W3C, OTel-ready)**
   - `TraceContext`, `TracePropagatorInterface`, `W3cTracePropagator`,
     `NullTracePropagator`, `CallbackTracePropagator` for APM bridges.
   - `propagate_trace` flag on publish/consume; `Amqp::setTracePropagator()`.

10. **Correlation ID propagation**
    - `CorrelationContext` with `propagate_correlation` integration on
      publish and `consumeWithLifecycle()`.

11. **Consumer lifecycle management**
    - `ConsumerLifecycle` hooks (starting/stopping/message/error), signal
      handlers, and `Amqp::consumeWithLifecycle()`.

### Workflows, Events, Middleware & Testing

12. **SAGA workflow helpers**
    - `Saga` builder with `step($name, $action, $compensation)` and
      reverse-order compensations on failure.
    - `SagaResult` reports succeeded/failed status, per-step results, the
      failing step, exception, and which steps were compensated.
    - `Amqp::saga($name)` shortcut.

13. **Laravel events**
    - New events under `Bschmitt\Amqp\Events\`: `MessagePublishing`,
      `MessagePublished`, `MessageReceived`, `MessageHandled`,
      `MessageFailed`.
    - Dispatched via `Illuminate\Support\Facades\Event` when available;
      fallback singleton `EventDispatcher` for non-Laravel contexts.

14. **Consume middleware pipeline**
    - `ConsumeMiddlewareInterface` and `ConsumePipeline`.
    - `Amqp::consumeWithMiddleware($queue, $handler, $middlewares, $properties)`.

15. **Fake AMQP test driver**
    - `Bschmitt\Amqp\Testing\FakeAmqp` extends `Amqp` with null
      publisher/consumer/factory stubs.
    - Laravel-style assertions: `assertPublished()`, `assertNotPublished()`,
      `assertNothingPublished()`, `assertPublishedCount()`.
    - `Amqp::fake()` swaps the bound singleton (or returns a standalone fake
      when no Laravel app is active).

16. **Publisher confirms & async publishing**
    - `AsyncPublisher` with persistent channel, `confirm_select`,
      `onAck()` / `onNack()` callbacks, and `flush()` / `stats()`.
    - `Amqp::asyncPublisher($properties)` shortcut.
    - Leverages existing `Publisher` confirms (`publisher_confirms`,
      `wait_for_confirms`, `waitForConfirms()`).

### Scale & Interop

17. **RPC abstraction helpers**
    - `RpcClient` + `RpcCallResult` with JSON mode and configurable
      timeouts.
    - `RpcServer` auto-reply consumer wrapper.
    - `Amqp::rpcClient()`, `rpcServer()`.

18. **Cross-service / polyglot messaging**
    - `InteropEnvelope` / `InteropMessage` with standard headers
      (`x-message-type`, `x-schema-version`, `x-source-service`).
    - `Amqp::publishInterop()`, `consumeInterop()`.

19. **Enhanced observability & queue metrics**
    - `MetricsCollector` with `Amqp::metrics()` (auto-increment on
      publish / consume).
    - `QueueMetrics` normalized view of Management API stats.
    - `Amqp::queueMetrics()`, `getQueueStats()` alias.

20. **High-performance worker optimizations**
    - `WorkerOptions` presets (`throughput`, `lowLatency`).
    - `HighPerformanceWorker`, `Amqp::consumeOptimized()`.
    - `amqp:work --optimized` (prefetch=50 when not overridden).

### gRPC-lite RPC

21. **Typed service-oriented RPC layer**
    - `RpcService` contract (`queue()`, `methods()`, optional
      `name()` / `exchange()` / `routingKey()`).
    - `RpcRequest` / `RpcResponse` DTOs with `make()` factory built on
      `TypedMessage` reflection.
    - `RpcDispatcher` coordinates symmetric `call()` / `serve()` /
      `register()` flow with `x-rpc-service` and `x-rpc-request`
      headers for routing and tracing.
    - `Rpc` facade auto-registered (`Rpc::call(UserService::class, GetUserRequest::make([...]))`).
    - `RpcException` (remote handler errors carry original class name) and
      `RpcTimeoutException`.
    - `Amqp::rpcDispatcher()` accessor; container-resolvable handler FQCNs.

### Tests

- ~140 new unit tests; total **405 unit tests** (925 assertions).
- Full suite passes on PHP 8.3 and 8.4; deprecation warnings on 8.4 come
  exclusively from the vendored Mockery library and predate this release.

### New & Updated Documentation

- New pages:
  - `docs/content/delayed-messaging.md`
  - `docs/content/typed-messaging.md`
  - `docs/content/schema-validation.md`
  - `docs/content/production-features.md`
  - `docs/content/workflow-events-testing.md`
  - `docs/content/scale-and-interop.md`
  - `docs/content/grpc-lite-rpc.md`
- Updated `docs/content/advanced.md`, `publishing.md`, `consuming.md`,
  `artisan-commands.md`, `best-practices.md`, `faq.md`,
  `getting-started.md`, `guide.md`, `USER_MANUAL.md`, `README.md`.
- New sidebar entries and feature cards in `docs/index.html`; new
  "Typed Messages" quick-start tab on the home page.

### Laravel Messaging Platform (phase 2)

The package now ships the building blocks of a full Laravel-first
microservice toolkit alongside the v3.4 core. Every item below is purely
additive and ships with unit-test coverage.

22. **Service Discovery (`Rpc::service('payments')`)**
    - `Bschmitt\Amqp\Rpc\ServiceRegistry` — register short names → service
      FQCNs (`Rpc::services()->register('payments', PaymentsService::class)`).
    - `autodiscover()` honours an opt-in `static alias()` method on
      `RpcService` subclasses.
    - `Bschmitt\Amqp\Rpc\ServiceCaller` — fluent caller with `timeout()` and
      `withProperties()` chaining; `Rpc::service($alias|$fqcn)->call($req)`.

23. **Saga facade + `compensate()` syntax**
    - `Saga::make()` static factory and a new top-level `Saga` facade.
    - Fluent compensation: `->step('reserve', $reserve)->compensate($release)`.
    - Backwards-compatible: the old 3-arg `step($name, $action, $comp)` form
      still works.

24. **Message contract dispatch**
    - `TypedMessage::make(array $payload)` and `TypedMessage::dispatch(array
      $payload, array $properties = [])` static helpers.
    - `TypedMessage::dispatchLater(array $payload, int $delayMs)` mirrors the
      delayed publisher.
    - Resolves the `Amqp` singleton from the Laravel container; throws a
      clear `RuntimeException` when called outside Laravel.

25. **Dead-letter management** (`Amqp::deadLetters()`)
    - `Bschmitt\Amqp\Support\DeadLetterManager` fluent API:
      `for($queue)->count()/messages()/replayTo($target)/purge()`.
    - Inspection uses the Management API; replay/purge use the AMQP channel
      directly so they work even when the management plugin is disabled.

26. **`#[Retry]` attribute + `RetryStrategy`**
    - `Bschmitt\Amqp\Attributes\Retry(attempts, strategy, delayMs,
      maxDelayMs, jitter)` with PHP 8+ attribute target.
    - `Bschmitt\Amqp\Support\RetryStrategy::{FIXED|EXPONENTIAL|LINEAR|NONE}`
      string constants (PHP 7.3-safe).
    - `RetryPolicy::fromAttribute($class, $method = null)` reflection
      helper builds an existing `RetryPolicy` from the attribute.
    - PHP 7.x silently ignores the attribute marker (parsed as a comment),
      so the package still loads on older runtimes.

27. **Monitoring dashboard + `amqp:monitor`**
    - `Bschmitt\Amqp\Support\MonitoringDashboard` aggregates
      `MetricsCollector` (in-process) + Management API queue stats into a
      single JSON-safe snapshot.
    - `Amqp::dashboard($queues)->snapshot()` returns
      `['process' => ..., 'queues' => ..., 'overview' => ..., 'generated' => ...]`.
    - `php artisan amqp:monitor --queue=orders [--json] [--connection=]`
      Artisan command for ops/CI.

28. **Causation ID propagation**
    - `CorrelationContext` now also tracks a causation id with
      `setCausation()` / `getCausation()` and `CAUSATION_HEADER`.
    - `inheritFromMessage()` captures the inbound `message_id` as the
      causation id of anything published next.
    - `applyToPublishProperties()` adds an `x-causation-id` header alongside
      the existing correlation headers.

29. **MessageStore (`Bschmitt\Amqp\Contracts\MessageStoreInterface`)**
    - Append-only log API with `append() / find() / all() / count() / purge()`.
    - `Bschmitt\Amqp\Support\InMemoryMessageStore` default implementation.
    - `Amqp::setMessageStore()` / `messageStore()` accessors; publish and
      consume both auto-record when a store is attached.
    - Foundation for durable replay / event-sourcing-style audit trails.

30. **Async Laravel events** (`ShouldPublishToAmqpInterface`)
    - Marker interface for Laravel events that should auto-publish to
      RabbitMQ.
    - `Bschmitt\Amqp\Events\AmqpEventListener` wildcard listener handles
      routing, payload, and exchange resolution (with overridable
      `amqpRouting()` / `amqpPayload()` / `amqpExchange()` hooks).
    - `Saga` facade alias auto-registered via `composer.json`.
    - Disabled by default; opt-in with `amqp.broadcast_laravel_events => true`.

### Migration

No migration required. All new features are opt-in:

- Existing handlers keep their two-argument signature; the typed third
  argument defaults to `null` when `--contract` is not used.
- `MessageHandlerInterface::handle()` gains an optional `$typed = null`
  parameter; implementations written against the old signature continue
  to work because the new argument has a default value.
- The default `MessageSerializerInterface` is lazily resolved as
  `JsonMessageSerializer` — existing publish/consume calls that send raw
  bodies are unaffected.
- MessageStore is `null` by default — no recording happens unless you call
  `Amqp::setMessageStore(...)`.
- The async-Laravel-events bridge is **only** registered when
  `amqp.broadcast_laravel_events` is set to `true`.

### Test counts

- Unit suite: **444 tests / 1004 assertions** (was 405 / 925 before phase 2).
- New unit test files: `ServiceRegistryTest`, `ServiceCallerTest`,
  `SagaFacadeTest`, `DeadLetterManagerTest`, `InMemoryMessageStoreTest`,
  `MonitoringDashboardTest`, `RetryAttributeTest`, `CausationContextTest`,
  `AmqpEventListenerTest`.
---

## Version 3.3.0 - Minor Release

This release broadens framework and PHP compatibility, improves configuration resolution, and expands CI coverage.

### Compatibility

- **PHP**: 7.3 through 8.5 (`composer.json`: `^7.3|^8.0`)
- **Laravel**: 7.x through 13.x in dev dependencies; CI matrix covers Laravel 8–13 across supported PHP versions
- **Laravel 8**: supports PHP 7.3 and 7.4 (Laravel 9+ requires PHP 8.0.2+)
- **Laravel 9**: requires PHP `^8.0.2`; CI/local installs use `platform.php` `8.0.2` (see `scripts/ci-platform-php.sh`)
- **PHPUnit**: `^9.6` on PHP 7.3/7.4; `^10.5|^11.5|^12.0` on PHP 8.0+ (resolved automatically by Composer)

### Features

1. **Configuration layouts**
   - `ConfigurationProvider` accepts current `use`/`properties`, legacy `default`/`connections`, and flat single-connection configs.

2. **CI and local testing**
   - GitHub Actions matrix for PHP 7.3–8.5 and Laravel 8–13.
   - `scripts/ci-platform-php.sh` and `scripts/ci-composer-install.sh` for correct Composer platform constraints.
   - `test-ci.sh` for running the CI matrix locally.

### Fixes

PHP 8.4+ and 8.5 compatibility fixes contributed by [@dlpro](https://github.com/dlpro) in [PR #136](https://github.com/bschmitt/laravel-amqp/pull/136) (merged May 18, 2026):

1. **PHP 8.4+ implicitly nullable parameters**
   - Added explicit `?` to nullable constructor parameters in `ConsumerFactory`, `PublisherFactory`, `Consumer`, and `Publisher` (avoids deprecation warnings on PHP 8.4 and fatal errors in PHP 9).
   - Fixed the same pattern in `DeadLetterExchangeIntegrationTest::createConfig()`.

2. **PHP 8.5 deprecations**
   - Removed `curl_close()` from `ManagementApiClient` (no-op since PHP 8.0, removed in PHP 8.5).
   - Dropped `ReflectionProperty::setAccessible()` calls in unit/integration tests (deprecated in PHP 8.5; no-op since PHP 8.1).
   - `ReflectionTestTrait` still calls `setAccessible(true)` on PHP 7.3/7.4 where reflection access requires it.

### Migration

No migration required. Existing `config/amqp.php` layouts continue to work.

---

## Version 3.1.2 - Patch Release

This patch release finalizes Laravel 13 compatibility and fixes RPC reply correlation handling in integration scenarios.

### Updates

1. **Laravel 13 Compatibility**
   - Verified package behavior and test suite with Laravel 13.
   - Updated package documentation to include Laravel 13 support.

2. **RPC Reliability Fix**
   - Fixed `Consumer::reply()` to publish `correlation_id` as an AMQP message property.
   - This ensures RPC clients can correctly match responses by correlation id.

3. **Integration Test Fixes**
   - Corrected `ReplyMethodIntegrationTest` usage of `consume()` to match the method signature.
   - Applied timeout/persistent behavior via consumer configuration.

### Validation

- Unit suite passes.
- Integration suite passes (with existing skips/warnings/deprecations as expected for environment-dependent tests).

---

## Version 3.1.1 - Patch Release

This patch release fixes critical issues that caused fatal errors and prepares the package for future php-amqplib versions.

### Bug Fixes

1. **Fixed #127: Removed Duplicate Class Files**
   - Removed duplicate class files (`src/Amqp.php`, `src/Consumer.php`, `src/Publisher.php`)
   - Fixed "Cannot declare class" fatal error
   - All classes now properly located in `src/Core/` directory per PSR-4 standards
   - This issue was introduced in commit 887a1e7 during namespace refactoring

2. **Fixed #128: Replaced Deprecated AMQPSSLConnection**
   - Updated `src/Core/Request.php` to use `AMQPConnectionFactory` and `AMQPConnectionConfig`
   - Updated `src/Managers/ConnectionManager.php` to use new API
   - Maps `ssl_options` to new `setSsl*` methods (`setSslCaCert`, `setSslCert`, `setSslKey`, etc.)
   - Maps `connect_options` to new timeout/heartbeat/keepalive methods
   - Maintains backward compatibility with existing configuration
   - Eliminates deprecation warnings, ready for php-amqplib v4

3. **Fixed Test Errors: Added Null Checks in tearDown() Methods**
   - Fixed TypeError issues when tests are skipped or setup fails
   - Added null checks for `testQueueName`, `alternateQueue`, `dlxQueue`, etc.
   - Fixed `ManagementApiIntegrationTest` `deletePolicy()` null check
   - Improved test reliability and error handling

### Impact

- **Critical**: Fixes fatal errors caused by duplicate class declarations
- **Important**: Eliminates deprecation warnings and prepares for php-amqplib v4
- **Improvement**: Better test reliability and error handling

### Migration

No migration required. This is a bug fix release that maintains full backward compatibility.

---

## Version 3.1.0 - Major Feature Release

This release introduces significant new features, improvements, and bug fixes to the Laravel AMQP package. The package now provides comprehensive support for RabbitMQ management operations, RPC patterns, message properties, and enhanced testing capabilities.

---

## Major New Features

### 1. RPC (Request-Response) Pattern Support

The package now includes built-in support for RPC patterns, making it easy to implement request-response communication between services.

#### New Methods

- **`Amqp::rpc()`** - Make RPC calls with automatic correlation ID and reply queue management
  ```php
  $response = Amqp::rpc('rpc-queue', 'request-data', [], 30);
  ```

- **`Consumer::reply()`** - Send RPC responses from consumer callbacks
  ```php
  Amqp::consume('rpc-queue', function ($message, $resolver) {
      $result = processRequest($message->body);
      $resolver->reply($message, $result);
      $resolver->acknowledge($message);
  });
  ```

- **`Amqp::listen()`** - Convenience method to auto-create queues and bind to multiple routing keys
  ```php
  Amqp::listen(['key1', 'key2'], function ($message, $resolver) {
      // Handle message
  });
  ```

#### Benefits

- Simplified RPC implementation
- Automatic correlation ID management
- Built-in timeout handling
- Support for request-response patterns in microservices

---

### 2. Queue and Exchange Management Operations

Direct programmatic control over RabbitMQ queues and exchanges.

#### New Methods

- **`Amqp::queueUnbind()`** - Unbind a queue from an exchange
- **`Amqp::exchangeUnbind()`** - Unbind an exchange from another exchange
- **`Amqp::queuePurge()`** - Remove all messages from a queue
- **`Amqp::queueDelete()`** - Delete a queue
- **`Amqp::exchangeDelete()`** - Delete an exchange

#### Example Usage

```php
// Purge all messages from a queue
Amqp::queuePurge('my-queue', ['queue' => 'my-queue']);

// Delete a queue
Amqp::queueDelete('my-queue', ['queue' => 'my-queue']);

// Unbind a queue from an exchange
Amqp::queueUnbind('my-queue', 'my-exchange', 'routing-key', [
    'queue' => 'my-queue',
    'exchange' => 'my-exchange'
]);
```

---

### 3. RabbitMQ Management HTTP API Integration

Full integration with RabbitMQ's Management HTTP API for monitoring and statistics.

#### New Methods

- **`Amqp::getQueueStats()`** - Get queue statistics (message count, consumer count, etc.)
- **`Amqp::getConnections()`** - List all active connections
- **`Amqp::getChannels()`** - List all active channels
- **`Amqp::getNodes()`** - Get cluster node information
- **`Amqp::getPolicies()`** - List all policies
- **`Amqp::createPolicy()`** - Create a new policy
- **`Amqp::updatePolicy()`** - Update an existing policy
- **`Amqp::deletePolicy()`** - Delete a policy
- **`Amqp::listFeatureFlags()`** - List all feature flags
- **`Amqp::getFeatureFlag()`** - Get status of a specific feature flag

#### Configuration

Add to your `config/amqp.php`:

```php
'management_api_url' => 'http://localhost:15672',
'management_api_user' => 'guest',
'management_api_password' => 'guest',
```

#### Example Usage

```php
// Get queue statistics
$stats = Amqp::getQueueStats('my-queue', '/');
// Returns: ['messages' => 10, 'consumers' => 2, ...]

// List all connections
$connections = Amqp::getConnections();

// Create a policy
Amqp::createPolicy('my-policy', '/', [
    'pattern' => '^my-queue$',
    'definition' => ['max-length' => 1000]
]);
```

---

### 4. Policy Management

Programmatic management of RabbitMQ policies for queue and exchange configuration.

#### Features

- Create, update, and delete policies
- Support for all policy definition options
- Integration with Management HTTP API

---

### 5. Feature Flags Support

Query RabbitMQ feature flags to determine available capabilities.

#### Methods

- **`Amqp::listFeatureFlags()`** - Get all feature flags and their status
- **`Amqp::getFeatureFlag()`** - Check if a specific feature flag is enabled

---

### 6. Enhanced Message Properties

Full support for standard AMQP message properties.

#### Supported Properties

- **Priority** - Message priority (0-255)
- **Correlation ID** - For RPC patterns
- **Reply-To** - For request-response patterns
- **Message ID** - Unique message identifier
- **Timestamp** - Message timestamp
- **Type** - Message type
- **User ID** - User identifier
- **App ID** - Application identifier
- **Expiration** - Message TTL
- **Content Type** - MIME type
- **Content Encoding** - Content encoding
- **Delivery Mode** - Persistent or transient
- **Application Headers** - Custom headers

#### Example Usage

```php
// Publish with message properties
Amqp::publish('routing-key', 'message', [
    'priority' => 10,
    'correlation_id' => 'unique-id',
    'reply_to' => 'reply-queue',
    'application_headers' => [
        'X-Custom-Header' => 'value'
    ]
]);

// Access properties in consumer
Amqp::consume('queue', function ($message, $resolver) {
    $priority = $message->getPriority();
    $correlationId = $message->getCorrelationId();
    $headers = $message->getHeaders();
});
```

---

### 7. Connection Configuration Helper

New method to retrieve connection configurations programmatically.

#### Method

- **`Amqp::getConnectionConfig()`** - Get configuration for a specific connection

#### Example Usage

```php
$config = Amqp::getConnectionConfig('production');
// Returns: ['host' => 'localhost', 'port' => 5672, ...]
```

---

## Improvements

### Consumer Prefetch (QoS)

- Enhanced prefetch configuration with dynamic adjustment
- Support for `qos_prefetch_count`, `qos_prefetch_size`, and `qos_a_global`
- Better control over message delivery rates

### Publisher Confirms

- Full support for publisher confirms
- Configurable acknowledgment handlers
- Support for `wait_for_confirms` and `publish_timeout`
- Return message handling for unroutable messages

### Queue Types

- Full support for Classic, Quorum, and Stream queue types
- Proper handling of queue type properties
- Validation and error handling

### Exchange Types

- Enhanced validation for exchange types
- Support for custom exchange types (with validation override)
- Better error messages for invalid exchange types

---

## Bug Fixes

### Fixed Issues

1. **Singleton Behavior** - Fixed issue where Publisher and Consumer properties persisted between calls
   - Each call now creates a new instance with merged properties
   - Prevents unexpected routing behavior

2. **Connection Management** - Improved connection and channel cleanup
   - Proper shutdown of connections and channels
   - Better resource management

3. **Configuration Handling** - Enhanced configuration provider
   - Better handling of property merging
   - Improved test environment compatibility

4. **Queue Declaration** - Fixed `PRECONDITION_FAILED` errors
   - Better handling of existing queues with different properties
   - Support for passive queue/exchange declaration

5. **Test Environment** - Improved test reliability
   - Better handling of Laravel facade in test environments
   - Enhanced integration test setup

---

## Documentation

### New Documentation

- Comprehensive developer documentation in wiki format
- Module-by-module feature documentation
- FAQ section addressing common issues
- RPC pattern usage guide
- Testing guide with examples
- Architecture documentation

### Updated Documentation

- Configuration guide with all new options
- Publishing and consuming examples
- Advanced features documentation
- Management API usage guide

---

## Testing

### Test Coverage

- **273 total tests** with comprehensive coverage
- Unit tests for all new features
- Integration tests against real RabbitMQ instances
- Tested with `rabbitmq:3-management` Docker image

### New Test Suites

- RPC method tests
- Management operation tests
- Management API integration tests
- Message properties tests
- Reply method tests

### Test Improvements

- Better test isolation
- Improved cleanup procedures
- Enhanced error handling in tests
- More reliable integration test setup

---

## Backward Compatibility

This release maintains full backward compatibility with previous versions:

- All existing methods continue to work as before
- Configuration file format remains compatible
- Existing code will work without modifications
- New features are opt-in

---

## Dependencies

- PHP 8.1+ (tested with PHP 8.3)
- Laravel 8.x / 9.x / 10.x / 11.x
- php-amqplib/php-amqplib (latest)
- RabbitMQ 3.x (tested with 3-management)

---

## Breaking Changes

**None** - This release is fully backward compatible.

---

## Migration Guide

No migration required. All existing code will continue to work. To use new features:

1. Update your `config/amqp.php` if you want to use Management API features
2. Use new methods as needed in your code
3. Review new documentation for best practices

---

## What's Next

Future improvements planned:

- Enhanced RPC timeout handling
- Better error recovery mechanisms
- Additional queue management operations
- Performance optimizations

---

## Acknowledgments

Special thanks to all contributors and the community for feedback and testing.

---

## Changelog Summary

### Added
- RPC pattern support (`rpc()`, `reply()`, `listen()`)
- Queue and exchange management operations
- Management HTTP API integration
- Policy management
- Feature flags support
- Enhanced message properties
- Connection configuration helper
- Comprehensive test suite
- Developer documentation

### Improved
- Consumer prefetch handling
- Publisher confirms support
- Queue type handling
- Exchange type validation
- Configuration management
- Test reliability
- Error messages

### Fixed
- Singleton behavior issues
- Connection cleanup
- Configuration handling
- Queue declaration errors
- Test environment compatibility

---

## Support

For issues, questions, or contributions, please visit:
- GitHub Issues: [https://github.com/bschmitt/laravel-amqp/issues](https://github.com/bschmitt/laravel-amqp/issues)
- Documentation: See `docs/` directory

---

**Release Date:** 2024
**Version:** 3.1.0
**Status:** Production Ready

---

## Version 3.1.1 - Patch Release

**Release Date:** December 2025
**Version:** 3.1.1
**Status:** Production Ready

