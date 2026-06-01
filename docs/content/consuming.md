# Consuming Messages

> **Looking for a CLI worker?** Use the [`amqp:work`](#artisan-commands) artisan command — it wraps everything on this page in a long-running process with QoS, memory caps, and graceful shutdown built in.

## Basic Consume

```php
use Bschmitt\\Amqp\\Facades\\Amqp;

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
    } catch (\\Exception $e) {
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
    } catch (\\Exception $e) {
        \\Log::error('Message processing failed', [
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

## Typed Consuming (`consumeTyped`)

Deserialize JSON bodies into contract DTOs automatically:

```php
use App\Messaging\OrderCreated;

$amqp = app('Amqp');
$amqp->consumeTyped('orders.queue', OrderCreated::class, function ($order, $message, $resolver) {
    processOrder($order->orderId, $order->total);
    $resolver->acknowledge($message);
});
```

When `OrderCreated::schema()` is defined, inbound payloads are validated before `fromPayload()` runs. Validation failures throw `SchemaValidationException` (compatible with `RetryHandler` / `consumeWithRetry`).

### CLI: `--contract` and `--validate-schema`

```bash
php artisan amqp:work orders \
    --handler="App\\Messaging\\ProcessOrderHandler" \
    --contract="App\\Messaging\\OrderCreated" \
    --validate-schema
```

The handler receives the deserialised contract as an optional **third** argument:

```php
public function handle(AMQPMessage $message, ConsumerInterface $resolver, $typed = null): void
{
    /** @var OrderCreated $typed */
    $this->orders->markPaid($typed->orderId);
    $resolver->acknowledge($message);
}
```
