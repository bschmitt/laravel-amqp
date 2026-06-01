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
- Message Priority - Priority-based message processing
- TTL Support - Message and queue expiration
- Lazy Queues - Disk-based message storage
- Alternate Exchange - Unroutable message handling
- **Native Laravel Queue integration** - Use `amqp` as a `config/queue.php` driver with `queue:work`
- **Artisan commands** - `amqp:work` (with `--retry`/`--retry-backoff`/`--dlq`/`--declare-topology`), `amqp:consume`, `amqp:listen`, `amqp:publish`, and `amqp:purge`

## Planned Features
- Delayed message and backoff support
- Typed message contracts & DTO serialization
- JSON schema validation for messages
- Exchange and topology builders
- Quorum queue & priority queue support
- Auto reconnect and heartbeat monitoring
- Connection pooling & persistent channels
- OpenTelemetry and distributed tracing support
- Correlation ID propagation
- Consumer lifecycle management
- Native SAGA workflow helpers
- Laravel event & middleware integration
- Improved testing and fake AMQP drivers
- Publisher confirms & async publishing
- RPC abstraction helpers
- Cross-service / polyglot messaging support
- Enhanced observability and queue metrics
- High-performance worker optimizations

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
```

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

See [Retry & Dead-Letter Abstractions](#retry--dead-letter-abstractions) for the full picture.

### Example handler

```php
namespace App\Messaging;

use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\MessageHandlerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class ProcessOrderHandler implements MessageHandlerInterface
{
    public function handle(AMQPMessage $message, ConsumerInterface $resolver): void
    {
        $order = json_decode($message->body, true);
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
- **[Release Notes](RELEASE_NOTES.md)** - Version 3.3.0 changelog (latest: 3.3.0 minor release)
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

Version 3.3.0 is fully backward compatible with previous versions. All existing code will continue to work without modifications.

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

**Version:** 3.3.0  
**Status:** Ready
