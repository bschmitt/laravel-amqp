# Publishing Messages

## Simple Publishing

```php
use Bschmitt\\Amqp\\Facades\\Amqp;

// Publish to default exchange and routing key
Amqp::publish('routing.key', 'Hello World');
```

## Publish with Custom Properties

```php
Amqp::publish('routing.key', 'Message', [
    'exchange' => 'my-exchange',
    'exchange_type' => 'direct',
    'queue' => 'my-queue',
]);
```

## Publish with Message Properties

```php
Amqp::publish('routing.key', 'Message', [
    'priority' => 10,
    'correlation_id' => 'unique-id',
    'reply_to' => 'reply-queue',
    'application_headers' => [
        'X-Custom-Header' => 'value'
    ],
]);
```

## Publish JSON Data

```php
$data = ['user_id' => 123, 'action' => 'login'];

Amqp::publish('user.events', json_encode($data), [
    'content_type' => 'application/json',
]);
```

## Exchange Types

### Topic Exchange

```php
Amqp::publish('user.created', 'message', [
    'exchange' => 'events',
    'exchange_type' => 'topic',
]);
```

### Direct Exchange

```php
Amqp::publish('high-priority', 'message', [
    'exchange' => 'tasks',
    'exchange_type' => 'direct',
]);
```

### Fanout Exchange

```php
Amqp::publish('', 'broadcast message', [
    'exchange' => 'amq.fanout',
    'exchange_type' => 'fanout',
]);
```

## Persistent Messages

```php
Amqp::publish('routing.key', 'important message', [
    'delivery_mode' => 2, // Persistent
    'queue_durable' => true,
]);
```

## Message TTL

```php
Amqp::publish('routing.key', 'temporary message', [
    'expiration' => '60000', // 60 seconds in milliseconds
]);
```

## Delayed Publishing (`publishLater`)

Schedule delivery after a delay without building TTL queues yourself:

```php
$amqp = app('Amqp');

$amqp->publishLater('orders.reminder', json_encode(['orderId' => 42]), 60000, [
    'exchange' => 'shop.events',
]);
```

Use `delay_strategy => 'plugin'` when the `rabbitmq-delayed-message-exchange` plugin is installed. See [Delayed Messaging](advanced.md#delayed-messaging--publish-backoff) for details.

## Typed Publishing (`publishTyped`)

```php
use App\Messaging\OrderCreated;

$amqp = app('Amqp');
$amqp->publishTyped(new OrderCreated('order-1', 19.99, 'USD'));

// Typed + delayed
$amqp->publishTypedLater(new OrderCreated('order-2', 9.99, 'USD'), 30000);
```

Outbound payloads are validated against `OrderCreated::schema()` when defined.

## Publisher-Side Backoff

```php
use Bschmitt\Amqp\Support\RetryPolicy;

$amqp->withPublishBackoff(RetryPolicy::fixed(3, 500))->run(function () use ($amqp) {
    return $amqp->publish('routing.key', $body);
});
```

## CLI: `amqp:publish --delay-ms`

```bash
php artisan amqp:publish order.reminder \
    --body='{"orderId":42}' \
    --exchange=shop.events \
    --delay-ms=60000

# With the delayed-message exchange plugin:
php artisan amqp:publish order.reminder --body='...' --delay-ms=60000 --delay-strategy=plugin
```
