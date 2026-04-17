# Advanced Features

## Publisher Confirms

Enable publisher confirms for guaranteed delivery:

```php
$publisher = app('amqp.publisher');
$publisher->enablePublisherConfirms();

$publisher->setAckHandler(function ($message) {
    // Message was acknowledged

});

$publisher->setNackHandler(function ($message) {
    // Message was not acknowledged

});

$publisher->publish('routing.key', 'message');
$publisher->waitForConfirms();
```

## Consumer Prefetch (QoS)

Control message delivery rate:

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

## Queue Types

### Quorum Queue

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Process message

}, [
    'queue_properties' => [
        'x-queue-type' => 'quorum',
    ],

]);
```

### Stream Queue

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Process message

}, [
    'queue_properties' => [
        'x-queue-type' => 'stream',
    ],
    'queue_durable' => true,

]);
```

## Dead Letter Exchanges

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\Exception $e) {
        // Reject without requeue - goes to DLQ
        $resolver->reject($message, false);

    }
}, [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx',
        'x-dead-letter-routing-key' => 'failed',
    ],
]);
```

## Message Priority

```php
// Configure queue with priority support
$amqp = app('Amqp');
$amqp->consume('priority-queue', function ($message, $resolver) {
    // Process message

}, [
    'queue_properties' => [
        'x-max-priority' => 10,
    ],
]);

// Publish with priority
Amqp::publish('routing.key', 'high priority', [
    'priority' => 10,
]);
```

## Lazy Queues

```php
$amqp = app('Amqp');
$amqp->consume('lazy-queue', function ($message, $resolver) {
    // Process message

}, [
    'queue_properties' => [
        'x-queue-mode' => 'lazy',
    ],
]);
```
