# Artisan Commands

Laravel AMQP ships with **five artisan commands** so you can run workers, fire off messages, and clean up queues without writing a single line of bootstrap code.

| Command | What it does |
|---------|--------------|
| `amqp:work` | Long-running worker -- like `queue:work`, but for raw AMQP handlers |
| `amqp:consume` | Process a fixed number of messages and exit (great for cron) |
| `amqp:listen` | Listen on one or more routing keys with an auto-generated queue |
| `amqp:publish` | Publish a single message from the CLI (debugging / smoke tests) |
| `amqp:purge` | Empty a queue, with an optional `--force` flag |

All consumer commands dispatch messages to a **handler class** you supply via `--handler`. The handler is resolved through the Laravel container, so constructor dependencies are auto-wired.

---

## Writing a handler

A handler can be any of three shapes — the package's `HandlerResolver` figures out which one you gave it:

### 1. Implement `MessageHandlerInterface` (recommended)

```php
namespace App\Messaging;

use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\MessageHandlerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class ProcessOrderHandler implements MessageHandlerInterface
{
    public function __construct(private OrderRepository $orders) {}

    public function handle(AMQPMessage $message, ConsumerInterface $resolver): void
    {
        $payload = json_decode($message->body, true);

        $this->orders->markPaid($payload['order_id']);

        $resolver->acknowledge($message);
    }
}
```

### 2. Make it invokable

```php
class ProcessOrderHandler
{
    public function __invoke(AMQPMessage $message, $resolver): void
    {
        // ... process ...
        $resolver->acknowledge($message);
    }
}
```

### 3. Pass a closure programmatically

The CLI always wants a class FQCN, but if you call `Amqp::consume()` yourself you can pass a closure with the same signature.

> **Note on `$resolver`**: the second argument is the active `ConsumerInterface` and exposes `acknowledge()`, `reject($message, $requeue)`, `reply()` (for RPC), and `stopWhenProcessed()`. If your handler returns without acknowledging the message, the worker will `reject` it for you — with or without requeue depending on `--requeue-on-error`.

---

## `amqp:work` — long-running worker

```bash
php artisan amqp:work my-queue --handler="App\Messaging\ProcessOrderHandler"
```

This is the headline command — keep it alive under a process manager (Supervisor, systemd, etc.) and it will continuously drain the queue.

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--handler=` | *(required)* | FQCN of the handler class |
| `--connection=` | `production` | Connection name from `config/amqp.php` |
| `--exchange=` | from config | Override the exchange name |
| `--exchange-type=` | from config | `topic`, `direct`, `fanout`, `headers` |
| `--routing-key=` | none | Routing key to bind (repeatable) |
| `--prefetch-count=` | none | Enable QoS with this prefetch count |
| `--max-messages=` | `0` (unlimited) | Stop after N messages |
| `--max-time=` | `0` (unlimited) | Stop after N seconds |
| `--memory=` | `128` | Exit if memory usage exceeds N MB |
| `--timeout=` | `0` (block) | Per-message wait timeout in seconds |
| `--stop-when-empty` | off | Exit once the queue is drained instead of waiting |
| `--requeue-on-error` | off | Requeue messages whose handler throws |
| `--retry=` | off | Wrap the handler in a `RetryHandler` with this many retry attempts (0 disables retries) |
| `--retry-delay=` | `1000` | Base delay between retries in milliseconds |
| `--retry-backoff=` | `fixed` | Backoff strategy (`fixed` or `exponential`) |
| `--retry-multiplier=` | `2.0` | Growth factor when using exponential backoff |
| `--retry-max-delay=` | `0` | Cap for the computed retry delay in ms (`0` = uncapped) |
| `--retry-jitter=` | `0` | Random jitter (ms) added to each retry delay |
| `--dlq=` | `{queue}.dlq` | Dead-letter queue name |
| `--declare-topology` | off | Pre-declare the work + DLQ + retry queues before consuming |
| `--quiet-iterations` | off | Suppress per-message output (only show errors and summary) |

> **Retry vs. requeue:** `--requeue-on-error` puts the message back at the
> head of the same queue and immediately re-delivers it. `--retry=N` instead
> routes the message through a per-delay retry queue (`{queue}.retry.{ms}`)
> with TTL-based delayed redelivery, and forwards it to the DLQ after the
> retry budget is exhausted. See
> [Advanced Retry & Dead-Letter Abstractions](#advanced-retry--dead-letter-abstractions)
> in the advanced guide for the full picture.

### Recipes

**Drain orders queue, exit when empty (great for one-off backfills):**

```bash
php artisan amqp:work orders \
    --handler="App\Messaging\ProcessOrderHandler" \
    --stop-when-empty
```

**Production worker with QoS, memory cap, and time-based recycling:**

```bash
php artisan amqp:work orders \
    --handler="App\Messaging\ProcessOrderHandler" \
    --prefetch-count=10 \
    --memory=256 \
    --max-time=3600
```

**Bind to specific routing keys on a custom exchange:**

```bash
php artisan amqp:work events \
    --handler="App\Messaging\EventHandler" \
    --exchange=app.events \
    --exchange-type=topic \
    --routing-key="user.*" \
    --routing-key="order.*"
```

**Production worker with retry budget, exponential backoff, and dedicated DLQ:**

```bash
php artisan amqp:work orders.process \
    --handler="App\Messaging\ProcessOrderHandler" \
    --retry=5 \
    --retry-backoff=exponential \
    --retry-delay=1000 \
    --retry-multiplier=2.0 \
    --retry-max-delay=60000 \
    --dlq=orders.process.failed \
    --declare-topology
```

The first run with `--declare-topology` idempotently creates `orders.process`,
`orders.process.failed`, and one `orders.process.retry.{ms}` queue per
distinct backoff delay. On subsequent runs you can drop the flag.

---

## `amqp:consume` — process a fixed number of messages

Use this when you want a **finite** consumer — for cron-driven jobs, scripted workflows, or simple drain operations.

```bash
# Process exactly one message (default)
php artisan amqp:consume my-queue --handler="App\Messaging\MyHandler"

# Process up to 10
php artisan amqp:consume my-queue --handler="App\Messaging\MyHandler" --max-messages=10

# Drain everything that's already in the queue
php artisan amqp:consume my-queue --handler="App\Messaging\MyHandler" --all
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--handler=` | *(required)* | FQCN of the handler class |
| `--max-messages=` | `1` | Number of messages to process before exiting |
| `--all` | off | Keep consuming until the queue is empty (overrides `--max-messages`) |
| `--prefetch-count=` | none | Enable QoS |
| `--timeout=` | `0` | Per-wait timeout in seconds |
| `--requeue-on-error` | off | Requeue messages when the handler throws |
| `--connection=`, `--exchange=`, `--exchange-type=`, `--routing-key=` | from config | Standard overrides |

---

## `amqp:listen` — bind to routing keys

A thin CLI wrapper around `Amqp::listen()`. It creates an **auto-deleted** queue (unless `--no-auto-delete` or `--queue=` is set) and binds it to every routing key you supply.

```bash
php artisan amqp:listen order.created order.updated order.cancelled \
    --handler="App\Messaging\OrderHandler"
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--handler=` | *(required)* | FQCN of the handler class |
| `--queue=` | auto-generated | Queue name (omit to get a uniquely-named throwaway queue) |
| `--exchange=` | from config | Override the exchange |
| `--exchange-type=` | `topic` | Exchange type |
| `--prefetch-count=` | none | Enable QoS |
| `--max-messages=`, `--max-time=` | `0` | Stop conditions (same semantics as `amqp:work`) |
| `--no-auto-delete` | off | Keep the queue around when the listener disconnects |
| `--requeue-on-error` | off | Requeue messages when the handler throws |
| `--connection=` | from config | Connection name |

> **Use case**: pub/sub-style event broadcasting. Spin up multiple `amqp:listen` workers with different routing key patterns and they'll each receive their own copy of every matching event.

---

## `amqp:publish` — publish from the CLI

Primarily a **debugging / smoke-test** utility. For application code, use `Amqp::publish()` directly.

```bash
# Inline body
php artisan amqp:publish order.created --body='{"id":42}' --exchange=orders --priority=5

# Body from a file
php artisan amqp:publish order.created --file=./payload.json --headers='{"X-Source":"cli"}'
```

### Options

| Option | Description |
|--------|-------------|
| `--body=` | Inline message body (required unless `--file` is set) |
| `--file=` | Read the body from this file path |
| `--exchange=` / `--exchange-type=` | Exchange overrides |
| `--queue=` | Declare/bind this queue before publishing |
| `--priority=` | Message priority (0-255) |
| `--correlation-id=` | `correlation_id` property |
| `--reply-to=` | `reply_to` property |
| `--message-id=` | `message_id` property |
| `--type=` | `type` property |
| `--expiration=` | Per-message TTL in milliseconds |
| `--content-type=` | `content_type` property |
| `--headers=` | JSON-encoded `application_headers`, e.g. `'{"X-Foo":"bar"}'` |
| `--mandatory` | Set the AMQP mandatory flag |
| `--connection=` | Connection name |

---

## `amqp:purge` — empty a queue

Prompts for confirmation by default; pass `--force` to skip it (useful in scripts).

```bash
php artisan amqp:purge dead-letters --force
```

| Option | Description |
|--------|-------------|
| `--force` | Skip the confirmation prompt |
| `--connection=` | Connection name |

The command prints the number of messages that were removed.

---

## Container integration

When you specify a handler by FQCN, the package uses the active container to instantiate it. That means **any constructor-injected dependency** in your handler is resolved automatically — repositories, loggers, HTTP clients, you name it.

```php
class ProcessOrderHandler implements MessageHandlerInterface
{
    public function __construct(
        private OrderRepository $orders,
        private LoggerInterface $log,
    ) {}

    public function handle(AMQPMessage $message, ConsumerInterface $resolver): void
    {
        // $this->orders and $this->log are wired by Laravel
    }
}
```

---

## Error handling

The consumer commands all share the same error contract:

1. The handler runs inside a `try/catch`.
2. If it throws, the message is **rejected** (no requeue by default) and a `failed` counter is incremented.
3. Use `--requeue-on-error` to put the message back on the queue for another attempt instead.
4. The exit code is `0` (success) when at least one message was processed without error, and non-zero when **every** delivery failed or the broker connection dies.

For production, combine `--requeue-on-error` with a **Dead-Letter Exchange** (see [Advanced Features](#advanced)) so poisoned messages don't loop forever.

---

## Supervisor example

Drop this into `/etc/supervisor/conf.d/amqp-workers.conf`:

```ini
[program:amqp-orders]
command=/usr/bin/php /var/www/html/artisan amqp:work orders --handler="App\Messaging\ProcessOrderHandler" --prefetch-count=10 --memory=256 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=4
process_name=%(program_name)s_%(process_num)02d
stopwaitsecs=60
```

Then reload Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start amqp-orders:*
```

---

## See also

- [Consuming Messages](#consuming) — the underlying API the commands wrap
- [Publishing Messages](#publishing) — the API behind `amqp:publish`
- [Laravel Queue Driver](#queue-driver) — for dispatching Laravel jobs (uses `queue:work`, not `amqp:work`)
- [RPC Pattern](#rpc) — handlers can call `$resolver->reply()` for request-response flows
