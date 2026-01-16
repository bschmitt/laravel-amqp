# Consuming Messages

## Basic Consume

```php
use Bschmitt\Amqp\Facades\Amqp;

$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    // Process message
    $data = $message->body;

    // Acknowledge message
    $resolver->acknowledge($message);

    // Stop consuming after processing
    $resolver->stopWhenProcessed();
});
```

## Consume with Options

```php
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'exchange' => 'my-exchange',
    'exchange_type' => 'direct',
    'routing' => ['routing.key'],
    'timeout' => 60,
    'message_limit' => 100,

]);
```

## Rejecting Messages

```php
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    try {
        // Process message
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\Exception $e) {
        // Reject and requeue
        $resolver->reject($message, true);

    }
});
```

## Error Handling

```php
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\Exception $e) {
        \Log::error('Message processing failed', [
            'error' => $e->getMessage(),
            'message' => $message->body,
        ]);

        // Reject without requeue (send to DLQ)
        $resolver->reject($message, false);

    }
});
```

## Consumer Prefetch (QoS)

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Process message

}, [
    'qos_prefetch_count' => 10,  // Max 10 unacked messages
    'qos_prefetch_size' => 0,    // No size limit
    'qos_a_global' => false,     // Per consumer

]);
```

## Listen to Multiple Routing Keys

```php
$amqp = app('Amqp');
$amqp->listen(['key1', 'key2', 'key3'], function ($message, $resolver) {
    // Handle message from any of the routing keys
    $resolver->acknowledge($message);

}, [
    'exchange' => 'my-exchange',
    'exchange_type' => 'topic',
]);
```
