# Laravel AMQP Package

A detailed AMQP wrapper for Laravel and Lumen to publish and consume messages, especially from RabbitMQ. This package provides full support for RabbitMQ features including RPC patterns, management operations, message properties, and more.

[![Build Status](https://travis-ci.org/bschmitt/laravel-amqp.svg?branch=master)](https://travis-ci.org/bschmitt/laravel-amqp)
[![CI](https://github.com/zfhassaan/laravel-amqp/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/zfhassaan/laravel-amqp/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/bschmitt/laravel-amqp/v/stable.svg)](https://packagist.org/packages/bschmitt/laravel-amqp)
[![PHP](https://img.shields.io/badge/PHP-7.3%20to%208.5-777BB4?logo=php&logoColor=white)](#requirements)
[![Laravel](https://img.shields.io/badge/Laravel-8%20to%2013-FF2D20?logo=laravel&logoColor=white)](#requirements)
[![License](https://poser.pugx.org/bschmitt/laravel-amqp/license.svg)](https://packagist.org/packages/bschmitt/laravel-amqp)
[![Total Downloads](https://img.shields.io/packagist/dt/bschmitt/laravel-amqp.svg?style=flat-square)](https://packagist.org/packages/bschmitt/laravel-amqp)


## Features

### Core Features
- Advanced queue configuration
- Easy message publishing to queues
- Flexible queue consumption with useful options
- Support for all RabbitMQ exchange types (topic, direct, fanout, headers)
- Full AMQP message properties support

### Version 3.1.0+ New Features
- **RPC Pattern Support** - Built-in request-response patterns with `rpc()` and `reply()` methods
- **Queue Management** - Programmatic control (purge, delete, unbind)
- **Management HTTP API** - Full integration with RabbitMQ Management API
- **Policy Management** - Create, update, and delete policies programmatically
- **Feature Flags** - Query RabbitMQ feature flags
- **Enhanced Message Properties** - Full support for priority, correlation_id, headers, etc.
- **Listen Method** - Auto-create queues and bind to multiple routing keys
- **Connection Configuration Helper** - Easy access to connection configs

### Advanced Features
- Publisher Confirms - Guaranteed message delivery
- Consumer Prefetch (QoS) - Rate limiting and flow control
- Queue Types - Classic, Quorum, and Stream queues
- Dead Letter Exchanges - Message routing for failed messages
- **Advanced retry & dead-letter abstractions** - Declarative `RetryPolicy` + `DeadLetterTopology` + `RetryHandler` with fixed/exponential backoff and auto-routing to DLQ when retries exhaust (see [Retry & DLQ Abstractions](#retry--dead-letter-abstractions))
- **Delayed messaging & publish backoff** - `publishLater()` / `publishTypedLater()` with TTL+DLX or `rabbitmq-delayed-message-exchange` plugin strategies, plus `PublishBackoff` for publisher-side transient retries (see [Delayed Messaging](#delayed-messaging--publish-backoff))
- **Typed message contracts** - `MessageContractInterface`, `TypedMessage` base class, `publishTyped()` / `consumeTyped()` with pluggable `MessageSerializerInterface` (JSON by default) (see [Typed Messaging](#typed-message-contracts--dto-serialization))
- **JSON schema validation** - Zero-dependency `SchemaValidator` (Draft 7 subset) validates payloads against contract `schema()` definitions (see [JSON Schema Validation](#json-schema-validation))
- Message Priority - Priority-based message processing
- TTL Support - Message and queue expiration
- Lazy Queues - Disk-based message storage
- Alternate Exchange - Unroutable message handling
- **Native Laravel Queue integration** - Use `amqp` as a `config/queue.php` driver with `queue:work`
- **Artisan commands** - `amqp:work` (with `--retry`/`--contract`/`--validate-schema`), `amqp:consume`, `amqp:listen`, `amqp:publish` (with `--delay-ms`), and `amqp:purge`
- **Exchange & topology builders** - `ExchangeTopology` declarative exchange + queue bindings with `declareExchangeTopology()` (see [Production Infrastructure](#production-infrastructure))
- **Quorum & priority queue profiles** - `QueueProfile::quorum()`, `priority()`, and `quorumWithPriority()` presets for `queue_properties` (see [Production Infrastructure](#production-infrastructure))
- **Resilient connections & pooling** - `ResilientConnectionManager` auto-reconnect with heartbeat staleness checks; `ConnectionPool` for persistent worker channels (see [Production Infrastructure](#production-infrastructure))
- **Distributed tracing** - W3C `traceparent` propagation via `TracePropagatorInterface` (OTel bridge via `CallbackTracePropagator`); enable with `propagate_trace` on publish/consume (see [Production Infrastructure](#production-infrastructure))
- **Correlation ID propagation** - `CorrelationContext` with `propagate_correlation` on publish/consume (see [Production Infrastructure](#production-infrastructure))
- **Consumer lifecycle hooks** - `ConsumerLifecycle` graceful shutdown, signal handlers, and `consumeWithLifecycle()` (see [Production Infrastructure](#production-infrastructure))
- **SAGA workflow helpers** - `Saga` step/compensation orchestrator with `SagaResult` reporting (see [SAGA, Events, Middleware & Testing](#saga-events-middleware--testing))
- **Laravel events & consume middleware** - `MessagePublishing`/`MessagePublished`/`MessageReceived`/`MessageHandled`/`MessageFailed` events plus `ConsumePipeline` / `ConsumeMiddlewareInterface` and `consumeWithMiddleware()` (see [SAGA, Events, Middleware & Testing](#saga-events-middleware--testing))
- **Fake AMQP test driver** - `Amqp::fake()` / `FakeAmqp` with `assertPublished()`, `assertPublishedCount()`, `assertNothingPublished()` (see [SAGA, Events, Middleware & Testing](#saga-events-middleware--testing))
- **Publisher confirms & async publishing** - persistent-channel `AsyncPublisher` with batched confirms via `Amqp::asyncPublisher()` (see [SAGA, Events, Middleware & Testing](#saga-events-middleware--testing))
- **RPC abstraction helpers** - `RpcClient` / `RpcServer` with `RpcCallResult`, JSON mode, and `Amqp::rpcClient()` / `rpcServer()` (see [Scale & Interop](#scale--interop))
- **Cross-service / polyglot messaging** - `InteropEnvelope` standard headers (`x-message-type`, `x-schema-version`, `x-source-service`) via `publishInterop()` / `consumeInterop()` (see [Scale & Interop](#scale--interop))
- **Observability & queue metrics** - `MetricsCollector`, `QueueMetrics`, `Amqp::metrics()`, `queueMetrics()` / `getQueueStats()` (see [Scale & Interop](#scale--interop))
- **High-performance workers** - `WorkerOptions`, `HighPerformanceWorker`, `consumeOptimized()`, and `amqp:work --optimized` (see [Scale & Interop](#scale--interop))
- **gRPC-lite typed RPC** - `Rpc::call(UserService::class, GetUserRequest::make([...]))` with typed request/response DTOs, service registries, and `Rpc::serve()` on the server (see [gRPC-lite RPC](#grpc-lite-rpc))
- **Service discovery** - `Rpc::service('payments')->call(...)` resolves a short name to a registered `RpcService` class; opt-in via `static alias()` on the service (see [Messaging Platform](#laravel-messaging-platform))
- **Saga facade** - `Saga::make()->step(...)->compensate(...)` fluent syntax with reverse-order compensation on failure (see [Messaging Platform](#laravel-messaging-platform))
- **Message contracts dispatch** - `OrderCreated::dispatch(['orderId' => 'o-1'])` auto-serializes and publishes typed messages via the Laravel container (see [Messaging Platform](#laravel-messaging-platform))
- **Dead-letter management** - `Amqp::deadLetters()->for('orders.dlq')->count()/messages()/replayTo()/purge()` fluent inspection and recovery (see [Messaging Platform](#laravel-messaging-platform))
- **Declarative retry attribute** - `#[Retry(attempts: 5, strategy: RetryStrategy::EXPONENTIAL)]` on handlers, hydrated via `RetryPolicy::fromAttribute()` (see [Messaging Platform](#laravel-messaging-platform))
- **Monitoring dashboard** - `Amqp::dashboard($queues)->snapshot()` aggregates in-process metrics + Management API stats; `php artisan amqp:monitor` prints them (see [Messaging Platform](#laravel-messaging-platform))
- **Causation ID propagation** - `CorrelationContext` now propagates both `correlation_id` and `x-causation-id` so consumers can chain "this happened because of that" (see [Messaging Platform](#laravel-messaging-platform))
- **MessageStore** - `MessageStoreInterface` + `InMemoryMessageStore`; opt-in audit log of every publish/consume via `Amqp::setMessageStore()` (see [Messaging Platform](#laravel-messaging-platform))
- **Async Laravel events** - mark events with `ShouldPublishToAmqpInterface` and enable `amqp.broadcast_laravel_events` to auto-publish `event(new OrderCreated())` to RabbitMQ (see [Messaging Platform](#laravel-messaging-platform))


## Planned Features

Status legend: **[x]** shipped · **[~]** partial / building blocks shipped (full feature still planned) · **[ ]** not started.

> Many "partial" items already ship as a programmatic API or CLI; the
> outstanding work is usually a UI, native integration, or codegen layer.
> See the [Features](#features) section above for the full list of
> already-shipped capabilities.

### Observability

* [ ] Laravel Pulse integration for AMQP metrics
* [ ] Native OpenTelemetry exporter support (W3C `traceparent` propagation **is** shipped — bridge via `CallbackTracePropagator`)
* [x] Queue throughput monitoring — `MetricsCollector`, `QueueMetrics`, `Amqp::metrics()` / `queueMetrics()`, `MonitoringDashboard`
* [~] Consumer lag monitoring — queue depth / ready / unacked via `getQueueStatistics()`; no dedicated lag metric yet
* [~] Dead-letter queue monitoring — `DeadLetterManager::count()` + DLQ surfaced in `amqp:monitor` / `MonitoringDashboard`
* [~] RPC latency tracking — `RpcCallResult` + `MessageHandled::$durationMs`; no built-in histograms
* [x] Distributed trace propagation — `TraceContext`, `W3cTracePropagator`, `propagate_trace` on publish/consume
* [~] Correlation ID visualization — `CorrelationContext` + `x-causation-id` propagation; no UI yet

---

### Developer Experience

* [ ] AMQP Explorer (Telescope-like message inspector)
* [~] Message replay tooling — `DeadLetterManager::replayTo()` + `MessageStoreInterface` seed; no full replay CLI/UI
* [~] Failed message browser — `DeadLetterManager::messages()` drains/inspects DLQ programmatically (no UI)
* [~] Live queue inspector — Management API + `queueMetrics()` / `getQueueStatistics()` (no UI)
* [ ] Message payload diff viewer
* [~] Schema validation debugger — `SchemaValidator`, contract `schema()`, `amqp:work --validate-schema` (not interactive)
* [ ] Interactive RPC testing console

---

### Kubernetes & Cloud Native

* [~] Kubernetes-ready consumer lifecycle management — `ConsumerLifecycle` + `consumeWithLifecycle()` hooks
* [x] Graceful shutdown support — `ConsumerLifecycle` signal handlers (`SIGTERM` / `SIGINT` via `pcntl`) + cooperative `requestStop()`
* [ ] Readiness probe endpoint
* [ ] Liveness probe endpoint
* [x] Auto-recovery after broker failures — `ResilientConnectionManager` (reconnect + heartbeat staleness) and `ConnectionPool`
* [ ] Consumer autoscaling recommendations
* [ ] Laravel Cloud compatibility
* [ ] Multi-region deployment support

---

### Enterprise Messaging

* [~] Dead-letter queue management UI — `DeadLetterManager` API + `amqp:monitor` CLI; UI still planned
* [ ] Scheduled message delivery (absolute time) — only **relative** delays today via `publishLater()` / `dispatchLater()`
* [x] Delayed message support — `DelayedPublisher`, `Amqp::publishLater()` / `publishTypedLater()`, `TypedMessage::dispatchLater()`, `amqp:publish --delay-ms`
* [x] Message priority queues — `QueueProfile::priority()` / `quorumWithPriority()`, `x-max-priority`, publish `priority`
* [x] Bulk publish operations — `Amqp::batchBasicPublish()` / `batchPublish()` + `BatchManager`
* [~] Consumer rate limiting — QoS / prefetch only (`basic_qos`, `--prefetch-count`, `WorkerOptions::throughput()` / `lowLatency()`); no app-level msgs/sec throttling
* [ ] Circuit breaker support
* [~] Retry policy dashboard — `RetryPolicy`, `#[Retry]`, `RetryHandler`, `consumeWithRetry()`; metrics via `amqp:monitor` (no dedicated retry UI)

---

### Polyglot Microservices

* [ ] Contract generation for TypeScript
* [ ] Contract generation for Go
* [ ] Contract generation for Python
* [~] JSON Schema export — contracts can declare `schema()` and validate in-process; no `export` CLI yet
* [ ] AsyncAPI specification generation
* [x] Service registry integration — `ServiceRegistry`, `Rpc::services()->register()` / `autodiscover()`, `Rpc::service('alias')->call(...)`
* [~] Cross-language RPC contracts — `InteropEnvelope` standard headers (`x-message-type`, `x-schema-version`, `x-source-service`); no codegen for foreign languages

---

### Operations

* [~] Horizon-style AMQP dashboard — `MonitoringDashboard` snapshot + `php artisan amqp:monitor [--json]`; full web UI still planned
* [~] Queue health monitoring — Management API stats + `amqp:monitor` (no dedicated health view)
* [~] Consumer health monitoring — consumer counts / rates via Management API; no consumer health UI
* [ ] Queue topology visualizer (`ExchangeTopology` / `DeadLetterTopology` are **code** builders, not a visualizer)
* [ ] Exchange / routing-key explorer
* [~] Production diagnostics commands — `amqp:monitor`, `amqp:publish`, `amqp:consume`, `amqp:listen`, `amqp:purge`, `amqp:work`; no dedicated `amqp:diagnose`
* [~] Broker connectivity diagnostics — `ResilientConnectionManager` handles reconnection; no standalone diagnostic command

---

### Security

* [ ] Message encryption support
* [ ] Message signing & verification
* [ ] Sensitive payload masking
* [~] Audit trail integration — `MessageStoreInterface` + `InMemoryMessageStore` (opt-in append log of every publish/consume)
* [ ] Per-consumer access controls
* [ ] Security scanning for message contracts

---

### AI & Modern Features

* [ ] AI-powered message anomaly detection
* [ ] AI-assisted queue optimization recommendations
* [ ] Natural language queue diagnostics
* [ ] Intelligent retry recommendations


## Requirements

- **PHP** 7.3 through 8.5 (`composer.json`: `^7.3|^8.0`)
- **Laravel** 8.x through 13.x (or Lumen 8.x+)
- RabbitMQ 3.x (tested with `rabbitmq:3-management` Docker image)

| Laravel | Minimum PHP | Notes |
|---------|-------------|--------|
| 8.x | 7.3 | Last Laravel version for PHP 7.3 / 7.4 |
| 9.x | 8.0.2 | Use PHP 8.0.2+ (not 8.0.0/8.0.1) |
| 10.x | 8.1 | |
| 11.x / 12.x | 8.2 | |
| 13.x | 8.3 | |

Config supports both `use` + `properties` (current) and legacy `default` + `connections` layouts.

## Installation

### Composer

```bash
composer require bschmitt/laravel-amqp
```

For Laravel 5.5+:
```json
"bschmitt/laravel-amqp": "^3.1"
```

For Laravel < 5.5:
```json
"bschmitt/laravel-amqp": "^2.0"
```

## Quick Start

### Publishing Messages

```php
use Bschmitt\Amqp\Facades\Amqp;

// Basic publish
Amqp::publish('routing-key', 'message');

// Publish with queue creation
Amqp::publish('routing-key', 'message', ['queue' => 'queue-name']);

// Publish with message properties
Amqp::publish('routing-key', 'message', [
    'priority' => 10,
    'correlation_id' => 'unique-id',
    'reply_to' => 'reply-queue',
    'application_headers' => [
        'X-Custom-Header' => 'value'
    ]
]);
```

### Consuming Messages

```php
use Bschmitt\Amqp\Facades\Amqp;

// Consume and acknowledge (using dynamic call)
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    echo $message->body;
    $resolver->acknowledge($message);
    $resolver->stopWhenProcessed();
});

// Consume forever
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    processMessage($message->body);
    $resolver->acknowledge($message);
}, ['persistent' => true]);

// Alternative: Using resolve() helper
$amqp = resolve('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    processMessage($message->body);
    $resolver->acknowledge($message);
});
```

### RPC Pattern

```php
// Client side - Make RPC call (using dynamic call)
$amqp = app('Amqp');
$response = $amqp->rpc('rpc-queue', 'request-data', [], 30);

// Server side - Process and reply (using dynamic call)
$amqp = app('Amqp');
$amqp->consume('rpc-queue', function ($message, $resolver) {
    $result = processRequest($message->body);
    $resolver->reply($message, $result);
    $resolver->acknowledge($message);
});
```

### Listen to Multiple Routing Keys

```php
$amqp = app('Amqp');
$amqp->listen(['key1', 'key2', 'key3'], function ($message, $resolver) {
    processMessage($message->body);
    $resolver->acknowledge($message);
});
```

## Artisan Commands

The package registers five console commands. Handler classes must implement `Bschmitt\Amqp\Contracts\MessageHandlerInterface` or expose an `__invoke($message, $resolver)` method. The `$resolver` is the active consumer and provides `acknowledge()`, `reject()`, `reply()`, and `stopWhenProcessed()`.

### `amqp:work` — long-running worker

```bash
php artisan amqp:work my-queue --handler="App\\Messaging\\ProcessOrderHandler"
```

| Option | Description |
|--------|-------------|
| `--handler=` | **Required.** FQCN of your message handler |
| `--connection=` | Connection name from `config/amqp.php` |
| `--exchange=` / `--exchange-type=` | Override exchange settings |
| `--routing-key=*` | Routing key(s) to bind (repeatable) |
| `--prefetch-count=` | Enable QoS with this prefetch count |
| `--max-messages=0` | Stop after N messages (0 = unlimited) |
| `--max-time=0` | Stop after N seconds |
| `--memory=128` | Exit if memory exceeds MB |
| `--stop-when-empty` | Exit when the queue is drained instead of waiting |
| `--requeue-on-error` | Requeue messages when the handler throws |

### `amqp:consume` — process a fixed number of messages

```bash
php artisan amqp:consume my-queue --handler="App\\Messaging\\ProcessOrderHandler" --max-messages=10
php artisan amqp:consume my-queue --handler="App\\Messaging\\ProcessOrderHandler" --all
```

Defaults to one message per invocation. Use `--all` to drain the queue.

### `amqp:listen` — listen on routing keys

```bash
php artisan amqp:listen order.created order.updated --handler="App\\Messaging\\OrderHandler"
```

Creates an auto-deleted queue (unless `--queue=` or `--no-auto-delete` is set) and binds it to every supplied routing key.

### `amqp:publish` — publish from the CLI

```bash
php artisan amqp:publish order.created --body='{"id":42}' --exchange=orders --priority=5
php artisan amqp:publish order.created --file=./payload.json --headers='{"X-Source":"cli"}'
php artisan amqp:publish order.created --body='{"id":42}' --delay-ms=5000 --exchange=orders
```

Use `--delay-ms` to schedule delivery (TTL+DLX by default, or `--delay-strategy=plugin` when the delayed-message exchange plugin is installed).

### `amqp:purge` — empty a queue

```bash
php artisan amqp:purge my-queue --force
```

### Retry options on `amqp:work`

| Option | Description |
|--------|-------------|
| `--retry=N` | Wraps the handler in a `RetryHandler` and configures up to `N` retries (0 disables retries) |
| `--retry-delay=ms` | Base delay between retries in milliseconds (default `1000`) |
| `--retry-backoff=fixed\|exponential` | Backoff strategy (default `fixed`) |
| `--retry-multiplier=2.0` | Growth factor for exponential backoff |
| `--retry-max-delay=ms` | Cap for the computed retry delay (`0` = uncapped) |
| `--retry-jitter=ms` | Random jitter added to each retry delay |
| `--dlq=name` | Override the dead-letter queue name (default `{queue}.dlq`) |
| `--declare-topology` | Pre-declare the work + DLQ + retry queues before consuming |
| `--contract=` | FQCN of a `MessageContractInterface` to deserialize bodies into (passed as 3rd handler arg) |
| `--validate-schema` | Validate inbound JSON against the contract's `schema()` before invoking the handler |

See [Retry & Dead-Letter Abstractions](#retry--dead-letter-abstractions) for the full picture.

### Example handler

```php
namespace App\Messaging;

use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\MessageHandlerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class ProcessOrderHandler implements MessageHandlerInterface
{
    public function handle(AMQPMessage $message, ConsumerInterface $resolver, $typed = null): void
    {
        $order = $typed !== null ? $typed->toPayload() : json_decode($message->body, true);
        // ... process $order ...
        $resolver->acknowledge($message);
    }
}
```

## Laravel Queue Driver

Use this package as a native Laravel queue backend so jobs can be dispatched with `dispatch()`, `Queue::push()`, and processed with `php artisan queue:work`.

### 1. Publish AMQP config

```bash
php artisan vendor:publish --provider="Bschmitt\Amqp\Providers\AmqpServiceProvider"
```

### 2. Add queue connection

Merge the example from `config/queue-amqp.php` into `config/queue.php`:

```php
'connections' => [
    // ...
    'amqp' => [
        'driver' => 'amqp',
        'connection' => env('AMQP_ENV', 'production'), // key in config/amqp.php properties
        'queue' => env('AMQP_QUEUE', 'default'),
        'retry_after' => 90,
    ],
],
```

### 3. Set default queue connection (optional)

```env
QUEUE_CONNECTION=amqp
```

### 4. Run the worker

```bash
php artisan queue:work amqp --queue=default
```

Jobs are published to your configured exchange with the queue name as the routing key. Delayed jobs use a TTL dead-letter queue per delay interval.

### Delayed & released jobs

```php
ProcessOrder::dispatch($order)->delay(now()->addMinutes(5));
```

`AmqpQueue::later()` publishes to a per-TTL delay queue (`{queue}.delay.{ttl_ms}`) with
`x-dead-letter-exchange` / `x-message-ttl` so RabbitMQ delivers the job back to the
main queue when the delay expires. `$job->release($seconds)` uses the same mechanism.

### Verify the driver

```bash
vendor/bin/phpunit --testdox \
  --filter 'AmqpQueue|AmqpJob|AmqpConnector|AmqpServiceProviderQueue|QueueConfigResolver|LaravelQueue'
```

Full setup, architecture and troubleshooting: [docs/content/queue-driver.md](docs/content/queue-driver.md) or the interactive docs site (`docs/index.html`).

## Configuration

### Laravel

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Bschmitt\Amqp\Providers\AmqpServiceProvider"
```

Or manually copy `vendor/bschmitt/laravel-amqp/config/amqp.php` to `config/amqp.php`.

### Lumen

Create a `config` folder in your Lumen root and copy the configuration file:

```bash
mkdir config
cp vendor/bschmitt/laravel-amqp/config/amqp.php config/amqp.php
```

Register the service provider in `bootstrap/app.php`:

```php
$app->configure('amqp');
$app->register(Bschmitt\Amqp\Providers\LumenServiceProvider::class);

// For Lumen 5.2+, enable facades
$app->withFacades(true, [
    'Bschmitt\Amqp\Facades\Amqp' => 'Amqp',
]);
```

### Configuration Example

```php
return [
    'use' => env('AMQP_ENV', 'production'),

    'properties' => [
        'production' => [
            'host'                => env('AMQP_HOST', 'localhost'),
            'port'                => env('AMQP_PORT', 5672),
            'username'            => env('AMQP_USER', 'guest'),
            'password'            => env('AMQP_PASSWORD', 'guest'),
            'vhost'               => env('AMQP_VHOST', '/'),
            'exchange'            => env('AMQP_EXCHANGE', 'amq.topic'),
            'exchange_type'       => env('AMQP_EXCHANGE_TYPE', 'topic'),
            'consumer_tag'        => 'consumer',
            'ssl_options'         => [],
            'connect_options'     => [],
            'queue_properties'    => ['x-ha-policy' => ['S', 'all']],
            'exchange_properties' => [],
            'timeout'             => 0,
            
            // Management API (optional)
            'management_api_url' => env('AMQP_MANAGEMENT_URL', 'http://localhost:15672'),
            'management_api_user' => env('AMQP_MANAGEMENT_USER', 'guest'),
            'management_api_password' => env('AMQP_MANAGEMENT_PASSWORD', 'guest'),
        ],
    ],
];
```

## Documentation

### Comprehensive Guides

- **[User Manual](docs/USER_MANUAL.md)** - Complete usage guide
- **[Release Notes](RELEASE_NOTES.md)** - Version 3.4.0 changelog (latest: 3.4.0 minor release)
- **[FAQ](docs/laravel-amqp.wiki/FAQ.md)** - Common questions and answers

### Wiki Documentation

- **[Getting Started](docs/laravel-amqp.wiki/Getting-Started.md)** - Installation and first steps
- **[Configuration](docs/laravel-amqp.wiki/Configuration.md)** - Configuration guide
- **[Publishing Messages](docs/laravel-amqp.wiki/Publishing-Messages.md)** - Publishing guide
- **[Consuming Messages](docs/laravel-amqp.wiki/Consuming-Messages.md)** - Consumption guide
- **[RPC Pattern](docs/laravel-amqp.wiki/RPC-Pattern.md)** - Request-response patterns
- **[Queue Management](docs/laravel-amqp.wiki/Queue-Management.md)** - Queue operations
- **[Management API](docs/laravel-amqp.wiki/Management-API.md)** - HTTP API integration
- **[Message Properties](docs/laravel-amqp.wiki/Message-Properties.md)** - Message properties
- **[Advanced Features](docs/laravel-amqp.wiki/Advanced-Features.md)** - Advanced usage
- **[Architecture](docs/laravel-amqp.wiki/Architecture.md)** - Package architecture
- **[Testing](docs/laravel-amqp.wiki/Testing.md)** - Testing guide

### Module Documentation

See [docs/modules/](docs/modules/) for detailed module documentation:
- RPC Module
- Management Operations
- Management API
- Message Properties
- Consumer Prefetch
- And more...

## Examples

### Fanout Exchange

```php
// Publishing
Amqp::publish('', 'message', [
    'exchange_type' => 'fanout',
    'exchange' => 'amq.fanout',
]);

// Consuming (using dynamic call)
$amqp = app('Amqp');
$amqp->consume('', function ($message, $resolver) {
    echo $message->body;
    $resolver->acknowledge($message);
}, [
    'routing' => '',
    'exchange' => 'amq.fanout',
    'exchange_type' => 'fanout',
    'queue_force_declare' => true,
    'queue_exclusive' => true,
    'persistent' => true
]);
```

### Queue Management

```php
// Get Amqp instance
$amqp = app('Amqp');

// Purge queue
$amqp->queuePurge('my-queue', ['queue' => 'my-queue']);

// Delete queue
$amqp->queueDelete('my-queue', ['queue' => 'my-queue']);

// Get queue statistics
$stats = $amqp->getQueueStats('my-queue', '/');
```

### Management API

```php
// Get Amqp instance
$amqp = app('Amqp');

// Get queue statistics
$stats = $amqp->getQueueStats('my-queue', '/');

// List connections
$connections = $amqp->getConnections();

// Create policy
$amqp->createPolicy('my-policy', [
    'pattern' => '^my-queue$',
    'definition' => ['max-length' => 1000]
], '/');
```

## Retry & Dead-Letter Abstractions

Three small primitives let you build production-grade retry pipelines without
hand-rolling DLX wiring:

- **`Bschmitt\Amqp\Support\RetryPolicy`** — declarative `max attempts` +
  backoff strategy (fixed, exponential, immediate, none) with optional cap
  and jitter.
- **`Bschmitt\Amqp\Support\DeadLetterTopology`** — describes the work queue,
  the DLQ, and the per-delay retry queues. Produces ready-to-use property
  arrays for `publish()` / `consume()`.
- **`Bschmitt\Amqp\Support\RetryHandler`** — decorator that wraps your
  handler. On exception it republishes the message to a TTL'd retry queue
  (which dead-letters back to the work queue when the TTL expires) and
  acknowledges the original delivery. When the retry budget is spent it
  rejects without requeue so RabbitMQ routes the message to the DLQ via the
  `x-dead-letter-exchange` configured on the work queue.

### Declare the topology once

```php
use Bschmitt\Amqp\Support\DeadLetterTopology;
use Bschmitt\Amqp\Support\RetryPolicy;

$amqp = app('Amqp');

// RetryPolicy::exponential($maxAttempts, $baseDelayMs, $multiplier, $maxDelayMs)
$policy   = RetryPolicy::exponential(5, 1000, 2.0, 60000);
$topology = DeadLetterTopology::for('orders.process', $policy)
    ->on('app.events', 'topic')
    ->withRoutingKey('orders.process');

// Idempotently creates: orders.process, orders.process.dlq,
// and orders.process.retry.{1000,2000,4000,8000,16000} (capped at 60000).
$amqp->declareRetryTopology($topology);
```

### Consume with auto-retry / DLQ routing

```php
$amqp->consumeWithRetry($topology, function ($message, $resolver) {
    processOrder(json_decode($message->body, true));
    $resolver->acknowledge($message);
});
```

When the handler throws:

1. `RetryHandler` reads (and bumps) the `x-retry-attempt` application header.
2. If the next attempt still fits the policy, the message is republished to
   `orders.process.retry.{delayMs}` with the computed TTL. RabbitMQ's DLX on
   that queue routes the message back to `orders.process` once the TTL
   expires.
3. When the retry budget is exhausted, the handler rejects the message
   without requeue and RabbitMQ forwards it to `orders.process.dlq` via the
   work queue's `x-dead-letter-exchange`.
4. The `x-first-failed-at` and `x-last-error` headers carry diagnostics
   forward across retries so DLQ inspection is meaningful.

### Pick a policy

```php
use Bschmitt\Amqp\Support\RetryPolicy;

RetryPolicy::fixed(3, 1000);                       // 3 retries, 1s apart
RetryPolicy::exponential(5, 500, 2.0, 30000);      // 500ms doubling, capped at 30s
RetryPolicy::immediate(2);                         // 2 retries with zero delay
RetryPolicy::none();                               // failures go straight to the DLQ
```

### Wrap an existing handler manually

```php
use Bschmitt\Amqp\Support\RetryHandler;

$wrapped = $amqp->retryHandler($yourHandler, $topology, function ($level, $message, $context) {
    Log::log($level, $message, $context);
});

$amqp->consume('orders.process', $wrapped, $topology->toWorkProperties());
```

### Driving the worker from the CLI

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

See `docs/content/advanced.md` and the unit tests under `test/Unit/Retry*` /
`test/Unit/DeadLetterTopologyTest.php` for more examples.

## Delayed Messaging & Publish Backoff

Schedule messages for later delivery or absorb transient broker errors on publish.

### `publishLater()` — schedule delivery

```php
$amqp = app('Amqp');

// TTL + dead-letter exchange (works on stock RabbitMQ)
$amqp->publishLater('orders.reminder', json_encode(['orderId' => 42]), 60000, [
    'exchange' => 'shop.events',
]);

// rabbitmq-delayed-message-exchange plugin (exchange must be x-delayed-message)
$amqp->publishLater('orders.reminder', $body, 60000, [
    'exchange' => 'shop.delayed',
    'delay_strategy' => 'plugin',
]);
```

`DelayedPublisher` creates a per-delay queue (`{routing}.delayed.{ms}`) with `x-message-ttl` and DLX routing back to the target exchange when using the default TTL strategy.

### `PublishBackoff` — retry failed publishes

```php
use Bschmitt\Amqp\Support\RetryPolicy;

$amqp->withPublishBackoff(RetryPolicy::exponential(3, 100, 2.0))->run(function () use ($amqp) {
    return $amqp->publish('orders.created', $payload);
});
```

This is separate from consumer-side `RetryHandler` — it retries the **publish** call itself when the broker throws.

## Typed Message Contracts & DTO Serialization

Define message shapes as plain PHP classes and let the package handle JSON encoding/decoding.

```php
use Bschmitt\Amqp\Support\TypedMessage;

class OrderCreated extends TypedMessage
{
    public $orderId;
    public $total;
    public $currency;

    public function __construct($orderId = null, $total = null, $currency = null)
    {
        $this->orderId = $orderId;
        $this->total = $total;
        $this->currency = $currency;
    }

    public static function routingKey()
    {
        return 'orders.created';
    }

    public static function exchange()
    {
        return 'shop.events';
    }
}
```

```php
$amqp = app('Amqp');

// Publish — picks up routing key + exchange from the contract
$amqp->publishTyped(new OrderCreated('order-1', 19.99, 'USD'));

// Consume — callback receives ($typed, $message, $resolver)
$amqp->consumeTyped('orders.queue', OrderCreated::class, function ($order, $message, $resolver) {
    processOrder($order->orderId);
    $resolver->acknowledge($message);
});

// Delayed typed publish
$amqp->publishTypedLater(new OrderCreated('order-2', 9.99, 'USD'), 30000);
```

Swap the serializer via `$amqp->setSerializer($mySerializer)` when you need MessagePack, Avro, etc.

## JSON Schema Validation

Contracts may expose a static `schema()` method returning a JSON Schema-style array. The package validates payloads on publish and consume using the bundled `SchemaValidator` (no external dependencies).

```php
class OrderCreated extends TypedMessage
{
    // ...properties...

    public static function schema()
    {
        return [
            'type' => 'object',
            'required' => ['orderId', 'total', 'currency'],
            'additionalProperties' => false,
            'properties' => [
                'orderId'  => ['type' => 'string', 'minLength' => 1],
                'total'    => ['type' => 'number', 'minimum' => 0],
                'currency' => ['type' => 'string', 'enum' => ['USD', 'EUR', 'GBP']],
            ],
        ];
    }
}
```

Invalid payloads raise `Bschmitt\Amqp\Exception\SchemaValidationException` with a list of pointer-style error messages. On the CLI, combine `--contract` with `--validate-schema` on `amqp:work`.

Supported keywords include `type`, `required`, `properties`, `additionalProperties`, `enum`, `const`, `minimum`/`maximum`, `minLength`/`maxLength`, `pattern`, `format` (email, uri, uuid, date, date-time), `items`, `oneOf`/`anyOf`/`allOf`/`not`, and more — see `docs/content/advanced.md`.

## Production Infrastructure

### Exchange topology builder

Declare an exchange and multiple bound queues in one fluent builder:

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Support\ExchangeTopology;
use Bschmitt\Amqp\Support\QueueProfile;

$topology = ExchangeTopology::exchange('events', 'topic')
    ->bindQueue('orders.created', 'order.created')
    ->bindQueue('orders.shipped', 'order.shipped', QueueProfile::quorum());

Amqp::declareExchangeTopology($topology);

// Publish using properties for a specific queue in the topology
Amqp::publish('order.created', $payload, $topology->propertiesForQueue('orders.created'));
```

Shortcut: `Amqp::exchangeTopology('events', 'topic')->bindQueue(...)`.

### Quorum & priority queues

```php
use Bschmitt\Amqp\Support\QueueProfile;

Amqp::publish('jobs', $payload, QueueProfile::quorumWithPriority(10)->mergeInto([
    'queue' => 'jobs',
    'routing' => 'jobs',
]));
```

### Resilient connections & connection pool

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Managers\ConnectionPool;

// Per-request resilient manager (reconnect + heartbeat staleness)
$resilient = Amqp::resilientConnection(['host' => 'rabbitmq'], [
    'max_reconnect_attempts' => 5,
    'heartbeat' => 30,
]);
$channel = $resilient->getChannel();

// Long-lived worker pool (persistent keys survive disconnectAll(false))
$pool = Amqp::connectionPool();
$manager = $pool->connection('worker', ['use' => 'production', 'resilient' => true], true);
```

### Correlation ID & distributed tracing

```php
use Bschmitt\Amqp\Support\CorrelationContext;

CorrelationContext::set('request-abc-123');

Amqp::publish('orders.created', $payload, [
    'propagate_correlation' => true,
    'propagate_trace' => true,
]);

Amqp::consumeWithLifecycle('orders.created', function ($message, $resolver) {
    // CorrelationContext::get() is populated when propagate_* flags are used
}, null, [
    'propagate_correlation' => true,
    'propagate_trace' => true,
]);
```

Bridge OpenTelemetry (or any APM) without a hard dependency:

```php
use Bschmitt\Amqp\Support\CallbackTracePropagator;

Amqp::setTracePropagator(new CallbackTracePropagator(
    function (array $carrier, $context) {
        // inject active span into $carrier
        return $carrier;
    },
    function (array $carrier) {
        // extract TraceContext from $carrier or return null
        return null;
    }
));
```

### Consumer lifecycle

```php
use Bschmitt\Amqp\Support\ConsumerLifecycle;

$lifecycle = (new ConsumerLifecycle())
    ->registerSignalHandlers()
    ->onStopping(function ($lifecycle) {
        // flush buffers, close DB connections, etc.
    });

Amqp::consumeWithLifecycle('jobs', $handler, $lifecycle);
```

See `docs/content/production-features.md` for the full reference.

## SAGA, Events, Middleware & Testing

### SAGA workflow

```php
use Bschmitt\Amqp\Facades\Amqp;

$saga = Amqp::saga('checkout')
    ->step('reserveStock', $reserveStock, $releaseStock)
    ->step('chargeCard',  $chargeCard,  $refundCard)
    ->step('shipOrder',   $shipOrder);

$result = $saga->execute(['orderId' => 42]);
if ($result->failed()) {
    Log::error('Saga failed', [
        'step' => $result->getFailedStep(),
        'compensated' => $result->getCompensatedSteps(),
        'error' => $result->getException()->getMessage(),
    ]);
}
```

Compensations only run for steps that completed before the failure, in reverse order.

### Laravel events

The package dispatches the following events through `\Illuminate\Support\Facades\Event` (and a local listener registry as a fallback for non-Laravel contexts):

| Event | When |
|------|------|
| `Bschmitt\Amqp\Events\MessagePublishing` | Right before a publish is sent |
| `Bschmitt\Amqp\Events\MessagePublished` | After a successful publish |
| `Bschmitt\Amqp\Events\MessageReceived` | When a message is received by the consume pipeline |
| `Bschmitt\Amqp\Events\MessageHandled` | After the handler completes |
| `Bschmitt\Amqp\Events\MessageFailed` | When the handler throws |

Listen in Laravel as usual:

```php
Event::listen(\Bschmitt\Amqp\Events\MessageFailed::class, function ($event) {
    Log::warning('AMQP handler failed', ['error' => $event->exception->getMessage()]);
});
```

### Consume middleware

Wrap the consume handler with a pipeline:

```php
use Bschmitt\Amqp\Facades\Amqp;

Amqp::consumeWithMiddleware('orders', function ($message, $resolver) {
    // handle...
}, [
    function ($message, $next) {
        $start = microtime(true);
        $next($message);
        Log::info('handled', ['duration_ms' => (microtime(true) - $start) * 1000]);
    },
    // ...or a ConsumeMiddlewareInterface instance
]);
```

Each middleware receives `(AMQPMessage $message, callable $next)` and can short-circuit by not calling `$next`.

### Fake AMQP driver

In tests, replace the bound singleton with a recording fake:

```php
use Bschmitt\Amqp\Core\Amqp;

public function test_publishes_order_created()
{
    $fake = Amqp::fake();

    (new CreateOrder)->handle();

    $fake->assertPublished('orders.created');
    $fake->assertPublishedCount(1, 'orders.created');
    $fake->assertNotPublished('orders.shipped');
}
```

The fake records both `publish()` and `publishLater()` calls; never touches the broker.

### Async publishing with publisher confirms

```php
$async = Amqp::asyncPublisher(['exchange' => 'events'])
    ->onAck(function ($tag)  { /* metric: published */ })
    ->onNack(function ($tag) { /* metric: failed */ });

foreach ($messages as $m) {
    $async->publish('events.created', json_encode($m));
}

if (!$async->flush(30)) {
    Log::warning('Some publisher confirms timed out');
}

$async->close();
```

`AsyncPublisher` keeps a single channel open with `confirm_select` and only waits for confirmations on `flush()`, so high-throughput publishers don't block on the per-message round-trip.

## Scale & Interop

### RPC abstraction

```php
// Client
$result = Amqp::rpcClient(['exchange' => 'rpc'])->asJson()->timeout(10)
    ->call('users.lookup', ['id' => 42]);

if ($result->succeeded()) {
    $user = $result->body();
}

// Server
Amqp::rpcServer()->asJson()->serve('rpc.users', function ($request, $consumer) {
    return ['id' => $request['id'], 'name' => 'Ada'];
});
```

`RpcCallResult` exposes `succeeded()`, `timedOut()`, and `body()`.

### Cross-service / polyglot messaging

```php
Amqp::publishInterop(
    'orders.created',
    ['orderId' => 99, 'total' => 12.50],
    'orders.created',
    'billing-service',
    ['exchange' => 'events'],
    '2.0'
);

Amqp::consumeInterop('events.orders', function ($interop, $raw, $resolver) {
    $payload = \Bschmitt\Amqp\Support\InteropEnvelope::decodePayload($interop);
    // $interop->messageType, $interop->sourceService, $interop->schemaVersion
});
```

Standard headers (`x-message-type`, `x-schema-version`, `x-source-service`) let Node, Go, or Java consumers route messages without PHP DTOs.

### Observability & queue metrics

```php
// In-process counters (per worker / request)
$stats = Amqp::metrics()->snapshot();

// Broker-side queue depth + rates (Management API)
$metrics = Amqp::queueMetrics('orders', '/');
Log::info('queue depth', $metrics->toArray());
```

Publish/consume paths increment `MetricsCollector` automatically when using `publish()`, `consumeWithMiddleware()`, or `HighPerformanceWorker`.

### High-performance workers

```php
Amqp::consumeOptimized('jobs', $handler, ['exchange' => 'work']);

// Or explicitly:
Amqp::highPerformanceWorker(
    \Bschmitt\Amqp\Support\WorkerOptions::throughput(100)
)->run('jobs', $handler);
```

CLI: `php artisan amqp:work jobs --handler=App\\Handlers\\JobHandler --optimized`

See `docs/content/scale-and-interop.md` for the full reference.

## gRPC-lite RPC

A typed, service-oriented RPC layer that feels like gRPC but rides on RabbitMQ. Define a service once, then call it from any process with typed DTOs.

### Define the service contract

```php
use Bschmitt\Amqp\Rpc\RpcService;
use Bschmitt\Amqp\Rpc\RpcRequest;
use Bschmitt\Amqp\Rpc\RpcResponse;

class UserService extends RpcService
{
    public static function queue(): string
    {
        return 'rpc.user-service';
    }

    public static function methods(): array
    {
        return [
            GetUserRequest::class    => 'getUser',
            CreateUserRequest::class => 'createUser',
        ];
    }
}

class GetUserRequest extends RpcRequest
{
    public $id;

    public function __construct($id = null) { $this->id = $id; }

    public static function responseClass()
    {
        return GetUserResponse::class;
    }
}

class GetUserResponse extends RpcResponse
{
    public $id;
    public $name;

    public function __construct($id = null, $name = null)
    {
        $this->id = $id;
        $this->name = $name;
    }
}
```

### Call from any client

```php
use Rpc; // facade alias auto-registered

$response = Rpc::call(
    UserService::class,
    GetUserRequest::make(['id' => 5])
);

echo $response->name; // GetUserResponse instance, hydrated for you
```

`Rpc::call()` automatically:
- Resolves the queue from the service contract.
- JSON-encodes the request DTO.
- Issues a synchronous AMQP RPC round-trip via the existing primitive.
- Hydrates the reply into the request's `responseClass()` (or returns the raw decoded array).

Throws `RpcTimeoutException` if no reply arrives, or `RpcException` if the server returned an error envelope.

### Serve on the server side

```php
use Rpc;

class UserServiceHandler
{
    public function getUser(GetUserRequest $request): GetUserResponse
    {
        $user = User::findOrFail($request->id);
        return GetUserResponse::make([
            'id'   => $user->id,
            'name' => $user->name,
        ]);
    }

    public function createUser(CreateUserRequest $request): GetUserResponse
    {
        $user = User::create(['name' => $request->name]);
        return GetUserResponse::make(['id' => $user->id, 'name' => $user->name]);
    }
}

Rpc::register(UserService::class, UserServiceHandler::class)
   ->serve(UserService::class);
```

The handler may be an instance or a container-resolvable FQCN (Laravel only). Handler exceptions are wrapped into an `_rpc_error` envelope so the client raises a typed `RpcException` with the original message and class name.

### Configurable

```php
// Global default timeout
Rpc::defaultTimeout(10);

// Per-call timeout + extra publish properties
Rpc::call(UserService::class, GetUserRequest::make(['id' => 1]), 5, [
    'exchange' => 'rpc.svc',
]);
```

See `docs/content/grpc-lite-rpc.md` for the full reference.

## Laravel Messaging Platform

A set of higher-level building blocks that turn the package from "an AMQP client" into a full microservice toolkit: service discovery, sagas, message contracts, dead-letter management, declarative retry, monitoring, automatic context propagation, an audit log, and an event bridge.

### Service Discovery (`Rpc::service(...)`)

Skip exchange/routing-key/queue gymnastics — register a short name and call by that name.

```php
use Bschmitt\Amqp\Facades\Rpc;

// Either: explicit registration
Rpc::services()->register('payments', PaymentsService::class);

// Or: opt-in auto-discovery (service exposes `public static function alias()`)
class PaymentsService extends RpcService {
    public static function queue(): string   { return 'rpc.payments'; }
    public static function methods(): array  { return [GetPayment::class => 'find']; }
    public static function alias(): ?string  { return 'payments'; }
}

Rpc::services()->autodiscover([PaymentsService::class]);

$response = Rpc::service('payments')
    ->timeout(5)
    ->call(GetPayment::make(['id' => 123]));
```

`Rpc::service()` accepts an alias **or** a service FQCN.

### Saga Facade

`Saga::make()->step()->compensate()` with reverse-order compensation when a step throws.

```php
use Bschmitt\Amqp\Facades\Saga;

$result = Saga::make('checkout')
    ->step('reserve', fn($ctx) => $stock->reserve($ctx['orderId']))
        ->compensate(fn($ctx) => $stock->release($ctx['orderId']))
    ->step('charge',  fn($ctx) => $payments->charge($ctx['amount']))
        ->compensate(fn($ctx, $tx) => $payments->refund($tx))
    ->execute(['orderId' => 1, 'amount' => 49.99]);

if (!$result->succeeded()) {
    Log::error('Saga failed at ' . $result->getFailedStep(), [
        'compensated' => $result->getCompensatedSteps(),
    ]);
}
```

### Message Contracts (`OrderCreated::dispatch(...)`)

`TypedMessage` now exposes `make()` and `dispatch()` (and `dispatchLater()` for the delayed-queue variant).

```php
use Bschmitt\Amqp\Support\TypedMessage;

class OrderCreated extends TypedMessage
{
    public $orderId;
    public $total;

    public static function name(): string { return 'orders.created'; }
}

OrderCreated::dispatch(['orderId' => 'o-1', 'total' => 9.99]);

OrderCreated::dispatchLater(['orderId' => 'o-1'], 2_000); // 2s delay
```

### Dead-Letter Management

```php
use Bschmitt\Amqp\Facades\Amqp;

Amqp::deadLetters()->for('orders.dlq')->count();           // 17
Amqp::deadLetters()->for('orders.dlq')->messages(10);      // drain & inspect
Amqp::deadLetters()->for('orders.dlq')->replayTo('orders', 50);
Amqp::deadLetters()->for('orders.dlq')->purge();
```

### Declarative Retry (`#[Retry]`)

```php
use Bschmitt\Amqp\Attributes\Retry;
use Bschmitt\Amqp\Support\RetryStrategy;
use Bschmitt\Amqp\Support\RetryPolicy;

class CreateOrderHandler
{
    #[Retry(attempts: 5, strategy: RetryStrategy::EXPONENTIAL, delayMs: 500)]
    public function handle($message): void { /* ... */ }
}

$policy = RetryPolicy::fromAttribute(CreateOrderHandler::class, 'handle');
$amqp->consumeWithRetry('orders', $handler, $policy);
```

On PHP 7.x the attribute parses as a comment (the package still loads); call sites that want the attribute need PHP 8+.

### Monitoring Dashboard

```php
$snapshot = Amqp::dashboard(['orders', 'orders.dlq'])->snapshot();
// process: { published, consumed, handled, failed }
// queues:  { 'orders' => { messages, consumers, publish_rate, ... } }
// overview, generated
```

CLI:

```bash
php artisan amqp:monitor --queue=orders --queue=orders.dlq
php artisan amqp:monitor --queue=orders --json
```

Wire the snapshot into any HTTP route (Laravel, Symfony, Slim) to expose a JSON dashboard.

### Causation ID Propagation

`CorrelationContext::inheritFromMessage()` now picks up the inbound `message_id` as the **causation_id** for everything published afterwards, so downstream services can trace "this happened because of that" through a chain.

```php
CorrelationContext::inheritFromMessage($incoming);

Amqp::publish('orders.created', $body, [
    'propagate_correlation' => true,
    'message_id' => uniqid('msg_', true),
]);
// outbound has `correlation_id`, `x-correlation-id`, and `x-causation-id` set
```

### MessageStore (audit log / event-sourcing seed)

```php
use Bschmitt\Amqp\Support\InMemoryMessageStore;

$amqp->setMessageStore(new InMemoryMessageStore());

Amqp::publish('orders.created', '{}');

$entries = $amqp->messageStore()->all(['direction' => 'published']);
```

Implement `MessageStoreInterface` to back it with Eloquent / Redis / files for durable replay.

### Async Laravel Events

```php
use Bschmitt\Amqp\Contracts\ShouldPublishToAmqpInterface;

class OrderCreated implements ShouldPublishToAmqpInterface
{
    public function __construct(public string $orderId) {}
}

// config/amqp.php
return [
    // ...
    'broadcast_laravel_events' => true,
];

event(new OrderCreated('o-1'));
// auto-published to RabbitMQ with routing key `order_created`
```

Override `amqpRouting()`, `amqpExchange()`, or `amqpPayload()` on the event to customise routing.

## Testing

The package includes comprehensive test coverage:

```bash
# Run all tests
php vendor/bin/phpunit

# Run unit tests only
php vendor/bin/phpunit test/Unit/

# Run integration tests only
php vendor/bin/phpunit test/Integration/
```

**Test Requirements:**
- RabbitMQ server running (for integration tests)
- Docker: `docker run -d --name rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:3-management`

See [Testing Guide](docs/laravel-amqp.wiki/Testing.md) for more information.

## Version 3.1.0+ Highlights

### New Methods

**RPC:**
- `$amqp->rpc($routingKey, $request, $properties, $timeout)` - Make RPC calls (use `$amqp = app('Amqp')`)
- `Consumer::reply($message, $response, $properties)` - Send RPC responses
- `$amqp->listen($routingKeys, $callback, $properties)` - Auto-create queues with multiple bindings (use `$amqp = app('Amqp')`)

**Management:**
- `$amqp->queuePurge($queue, $properties)` - Purge queue (use `$amqp = app('Amqp')`)
- `$amqp->queueDelete($queue, $ifUnused, $ifEmpty, $properties)` - Delete queue
- `$amqp->queueUnbind(...)` - Unbind queue
- `$amqp->exchangeDelete(...)` - Delete exchange
- `$amqp->exchangeUnbind(...)` - Unbind exchange

**Management API:**
- `$amqp->getQueueStats($queue, $vhost, $properties)` - Queue statistics (use `$amqp = app('Amqp')`)
- `$amqp->getConnections($connectionName, $properties)` - List connections
- `$amqp->getChannels($channelName, $properties)` - List channels
- `$amqp->getNodes($nodeName, $properties)` - Cluster nodes
- `$amqp->getPolicies($properties)` - List policies
- `$amqp->createPolicy(...)` - Create policy
- `$amqp->updatePolicy(...)` - Update policy
- `$amqp->deletePolicy(...)` - Delete policy
- `$amqp->listFeatureFlags($properties)` - List feature flags
- `$amqp->getFeatureFlag($name, $properties)` - Get feature flag

**Helpers:**
- `$amqp->getConnectionConfig($connectionName)` - Get connection config (use `$amqp = app('Amqp')`)

**Note:** For `consume()`, `listen()`, `rpc()`, and all management methods, you must resolve the Amqp instance from the container using `$amqp = app('Amqp')` or `$amqp = resolve('Amqp')`. The static facade `Amqp::` works for `publish()` but not for `consume()` and other instance methods.

## Backward Compatibility

Version 3.4.0 is fully backward compatible with previous versions. All existing code will continue to work without modifications.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Credits

- Some concepts were used from [mookofe/tail](https://github.com/mookofe/tail)
- Built and tested with `rabbitmq:3-management` Docker image

## License

This package is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).

## Support

For issues, questions, or contributions:
- GitHub Issues: [https://github.com/bschmitt/laravel-amqp/issues](https://github.com/bschmitt/laravel-amqp/issues)
- Documentation: See `docs/` directory
- FAQ: [docs/laravel-amqp.wiki/FAQ.md](docs/laravel-amqp.wiki/FAQ.md)

---

**Version:** 3.4.0  
**Status:** Ready
