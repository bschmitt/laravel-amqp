# Native Laravel Queue Driver

Use this package as a **first-class Laravel queue backend**. Jobs dispatched through Laravel's normal `dispatch()` / `Queue::push()` APIs are persisted in RabbitMQ and processed by `php artisan queue:work`, while still benefiting from AMQP features like exchanges, routing keys and dead-letter queues.

## Why use it?

- Keep using familiar Laravel job classes, `dispatch()`, `->onQueue()` and `->delay()`.
- Run `php artisan queue:work amqp` exactly like the `database` or `redis` driver.
- Get RabbitMQ durability, fan-out and DLX semantics for free.
- Works alongside direct `Amqp::publish()` / `Amqp::consume()` usage.

## 1. Install & publish config

```bash
composer require bschmitt/laravel-amqp
php artisan vendor:publish --provider="Bschmitt\Amqp\Providers\AmqpServiceProvider"
```

Publishing copies both `config/amqp.php` and `config/queue-amqp.php` into your app.

## 2. Register the amqp queue connection

Merge the example entry from `config/queue-amqp.php` into `config/queue.php` under `connections`:

```php
'connections' => [
    // ... existing connections ...

    'amqp' => [
        'driver'       => 'amqp',
        'connection'   => env('AMQP_ENV', 'production'), // key in config/amqp.php properties
        'queue'        => env('AMQP_QUEUE', 'default'),
        'retry_after'  => (int) env('AMQP_QUEUE_RETRY_AFTER', 90),
        'block_for'    => null,
        'after_commit' => false,
    ],
],
```

## 3. Environment variables

```env
QUEUE_CONNECTION=amqp

AMQP_HOST=localhost
AMQP_PORT=5672
AMQP_USER=guest
AMQP_PASSWORD=guest
AMQP_VHOST=/
AMQP_QUEUE=default
```

## 4. Start a worker

```bash
php artisan queue:work amqp --queue=default
```

## Dispatching jobs

### Immediate dispatch

```php
use App\Jobs\ProcessOrder;

ProcessOrder::dispatch($order);
```

### Routed to a specific queue

```php
ProcessOrder::dispatch($order)->onQueue('orders.high');
```

### Delayed dispatch

```php
ProcessOrder::dispatch($order)->delay(now()->addMinutes(5));
```

Delayed jobs are routed to an auto-created TTL queue (one per delay interval) that dead-letters back to the main queue when the TTL expires — RabbitMQ performs the scheduling without polling or extra services.

## How it works under the hood

```
dispatch(Job)
    │
    ▼
AmqpQueue::push() ──── basic_publish() ───▶  exchange (amq.topic by default)
                                                │
                                                │  routing-key = queue name
                                                ▼
                                            main queue ──▶ queue:work pop()
                                                ▲
                                                │ DLX on TTL expiry
                                                │
                                       jobs.delay.{ttl_ms}
                                                ▲
                                                │
                                       laterRaw($delay, ...)
```

- The **routing key** equals the queue name unless `routing` is overridden in `config/amqp.php`.
- Each message is published with `delivery_mode = 2` (persistent).
- Attempt counts are stored in the `application_headers.laravel.attempts` AMQP header so `$job->attempts()` reflects retries correctly.
- `$job->release($delay)` re-publishes a fresh copy with an incremented attempt counter (or rejects-with-requeue when `$delay === 0`).

## Configuration reference

The `amqp` connection inherits everything from `config/amqp.php → properties.{connection}` and lets you override any of these keys directly inside `config/queue.php`:

| Key | Description |
|-----|-------------|
| `host`, `port`, `username`, `password`, `vhost` | Broker credentials |
| `exchange`, `exchange_type` | Where job messages are published |
| `routing` | Custom routing key (defaults to queue name) |
| `queue_durable`, `queue_auto_delete`, `queue_properties` | Queue declaration flags |
| `queue_force_declare` | Forced to `true` for queue-driver paths |

```php
'amqp' => [
    'driver'         => 'amqp',
    'connection'     => 'production',
    'queue'          => 'jobs',
    'exchange'       => 'app.jobs',
    'exchange_type'  => 'direct',
    'queue_durable'  => true,
    'queue_properties' => [
        'x-max-priority' => 10,
    ],
],
```

## Architecture

| Class | Role |
|-------|------|
| `AmqpServiceProvider` | Registers `QueueManager::extend('amqp', …)` |
| `AmqpConnector` | Builds an `AmqpQueue` from `config/queue.php` |
| `AmqpQueue` | Implements `push`, `later`, `pop`, `size` |
| `AmqpJob` | Wraps `AMQPMessage`; `delete()` ACKs, `release()` requeues |
| `QueueConfigResolver` | Merges `queue.php` + `amqp.php` connection properties |

## Verifying the integration

The package ships with a comprehensive test suite that exercises the queue driver end-to-end against a real broker:

| Test class | What it verifies |
|------------|------------------|
| `AmqpQueueTest` | Payload shape, routing, delivery mode, delay-queue topology |
| `AmqpJobTest` | `delete()` (ack), `release()` with/without delay, `attempts()` |
| `AmqpConnectorTest` | Connector returns wired `AmqpQueue` |
| `AmqpServiceProviderQueueTest` | `Queue::extend('amqp')` registration |
| `QueueConfigResolverTest` | Config merge and fallbacks |
| `LaravelQueueIntegrationTest` | push/pop/size/ack against RabbitMQ |
| `LaravelQueueDelayIntegrationTest` | DLX + TTL delay round-trip |
| `LaravelQueueReleaseIntegrationTest` | `release(0)` and `release($delay)` |
| `LaravelQueueWorkerIntegrationTest` | Full `QueueManager → Worker → Job` pipeline |

Run the queue-driver tests only:

```bash
vendor/bin/phpunit --testdox \
  --filter 'AmqpQueue|AmqpJob|AmqpConnector|AmqpServiceProviderQueue|QueueConfigResolver|LaravelQueue'
```

## Troubleshooting

### `InvalidArgumentException: No connector for [amqp]`

The service provider didn't boot. Ensure `composer require` auto-discovery ran or register `Bschmitt\Amqp\Providers\AmqpServiceProvider` manually.

### Delayed jobs never arrive in the main queue

The main queue must exist **before** the delay TTL expires so the DLX has a destination. Call `$queue->size()` once or run `queue:work` briefly to force broker-side declaration.

### Worker pops nothing despite messages in the queue

The `routing` property may not match the queue name. Remove the `routing` override or set it explicitly to your queue name.

### `$job->attempts()` throws on externally published messages

Fixed in recent versions: `AmqpJob` now safely defaults to attempt `1` when the `application_headers` property is absent.
