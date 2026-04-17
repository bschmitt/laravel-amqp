# Publishing Messages

## Simple Publishing

```php
use Bschmitt\Amqp\Facades\Amqp;

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
