# Message Properties

## Setting Message Properties

```php
use Bschmitt\Amqp\Facades\Amqp;

Amqp::publish('routing.key', 'Message', [
    // Standard properties
    'priority' => 10,                    // 0-255
    'correlation_id' => 'unique-id',
    'reply_to' => 'reply-queue',
    'message_id' => 'msg-123',
    'timestamp' => time(),
    'type' => 'notification',
    'user_id' => 'user123',
    'app_id' => 'my-app',
    'expiration' => '60000',             // TTL in milliseconds
    'content_type' => 'application/json',
    'content_encoding' => 'utf-8',
    'delivery_mode' => 2,                // 2 = persistent


    // Custom headers
    'application_headers' => [
        'X-Custom-Header' => 'value',
        'X-Request-ID' => 'req-123',
    ],
]);
```

## Accessing Message Properties

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Get properties
    $priority = $message->getPriority();
    $correlationId = $message->getCorrelationId();
    $replyTo = $message->getReplyTo();
    $headers = $message->getHeaders();
    $customHeader = $message->getHeader('X-Custom-Header');


    // Process message
    $resolver->acknowledge($message);

});
```

## Common Use Cases

### Priority Messages

```php
// High priority
Amqp::publish('tasks', 'urgent task', [
    'priority' => 10,
    'queue_properties' => [
        'x-max-priority' => 10,
    ],

]);

// Normal priority
Amqp::publish('tasks', 'normal task', [
    'priority' => 5,
]);

// Low priority
Amqp::publish('tasks', 'low priority task', [
    'priority' => 1,

]);
```

### Message TTL

```php
// Message expires in 60 seconds
Amqp::publish('routing.key', 'temporary message', [
    'expiration' => '60000', // milliseconds

]);
```

### Custom Headers

```php
Amqp::publish('routing.key', 'message', [
    'application_headers' => [
        'X-User-ID' => '123',
        'X-Request-ID' => 'req-456',
        'X-Retry-Count' => 0,
    ],
]);
```
