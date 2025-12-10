# Lazy Queues Documentation

## Overview

Lazy queues are a RabbitMQ feature that helps manage large message backlogs by keeping messages on disk instead of in memory. This feature is particularly useful when dealing with queues that can accumulate many messages.

## Feature Support

✅ **Fully Supported** - The `x-queue-mode` property is supported through the `queue_properties` configuration.

## Configuration

### Basic Configuration

Add `x-queue-mode` to your `queue_properties` in the AMQP configuration:

```php
'queue_properties' => [
    'x-queue-mode' => 'lazy'  // or 'default'
]
```

### Configuration Options

- **`lazy`**: Messages are kept on disk, reducing memory usage for large backlogs
- **`default`**: Standard queue behavior (messages kept in memory when possible)

### Example Configuration

```php
// config/amqp.php
return [
    'properties' => [
        'production' => [
            // ... other settings ...
            'queue_properties' => [
                'x-queue-mode' => 'lazy',
                'x-max-length' => 1000,
                'x-message-ttl' => 60000
            ],
        ],
    ],
];
```

## Use Cases

### When to Use Lazy Queues

1. **Large Message Backlogs**: When queues can accumulate thousands or millions of messages
2. **Memory Constraints**: When you need to reduce memory usage on RabbitMQ servers
3. **Slow Consumers**: When consumers process messages slower than producers publish them
4. **Batch Processing**: When processing messages in batches with long intervals

### When NOT to Use Lazy Queues

1. **Low Latency Requirements**: Lazy queues have slightly higher latency due to disk I/O
2. **Small Queues**: For queues that typically have few messages, default mode is more efficient
3. **High Throughput**: If you need maximum throughput, default mode may be better

## How It Works

### Lazy Mode (`x-queue-mode: lazy`)

- Messages are written to disk immediately upon publishing
- Messages are loaded into memory only when they need to be delivered to consumers
- Reduces memory footprint significantly for large queues
- Slightly higher latency due to disk I/O operations

### Default Mode (`x-queue-mode: default`)

- Messages are kept in memory when possible
- Faster message delivery
- Higher memory usage for large queues
- Better for low-latency requirements

## Code Examples

### Publishing to a Lazy Queue

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Models\Message;

// Configure lazy queue
config(['amqp.properties.production.queue_properties' => [
    'x-queue-mode' => 'lazy'
]]);

// Publish message
$message = new Message('Hello from lazy queue', [
    'content_type' => 'text/plain'
]);

Amqp::publish('routing.key', $message);
```

### Consuming from a Lazy Queue

```php
use Bschmitt\Amqp\Facades\Amqp;

// Configure lazy queue
config(['amqp.properties.production.queue_properties' => [
    'x-queue-mode' => 'lazy'
]]);

// Consume messages
Amqp::consume('queue-name', function ($message, $resolver) {
    echo "Received: " . $message->body . "\n";
    $resolver->ack();
});
```

### Combining with Other Properties

Lazy queues work well with other queue properties:

```php
'queue_properties' => [
    'x-queue-mode' => 'lazy',
    'x-max-length' => 10000,        // Limit queue size
    'x-message-ttl' => 3600000,    // 1 hour TTL
    'x-dead-letter-exchange' => 'dlx'
]
```

## Testing

### Unit Tests

Unit tests are available in `test/LazyQueueTest.php`:

- `testQueueDeclareWithLazyMode()` - Tests lazy mode configuration
- `testQueueDeclareWithDefaultMode()` - Tests default mode configuration
- `testQueueDeclareWithLazyModeAndOtherProperties()` - Tests combining with other properties
- `testQueueDeclareWithoutQueueMode()` - Tests default behavior without mode specified

### Integration Tests

Integration tests are available in `test/LazyQueueIntegrationTest.php`:

- `testQueueDeclareWithLazyMode()` - Tests queue declaration with lazy mode
- `testPublishAndConsumeWithLazyMode()` - Tests publishing and consuming with lazy mode
- `testLazyModeWithMaxLength()` - Tests lazy mode combined with max-length
- `testDefaultModeQueue()` - Tests default mode behavior

Run tests:

```bash
# Unit tests
php vendor/bin/phpunit test/LazyQueueTest.php

# Integration tests (requires RabbitMQ)
php vendor/bin/phpunit test/LazyQueueIntegrationTest.php
```

## Performance Considerations

### Memory Usage

- **Lazy Mode**: Memory usage is proportional to the number of consumers, not queue length
- **Default Mode**: Memory usage is proportional to queue length

### Latency

- **Lazy Mode**: Slightly higher latency due to disk I/O (typically < 1ms additional)
- **Default Mode**: Lower latency (messages already in memory)

### Throughput

- **Lazy Mode**: Slightly lower throughput due to disk I/O
- **Default Mode**: Higher throughput for small to medium queues

## Best Practices

1. **Use Lazy Mode for Large Queues**: If your queue regularly has > 10,000 messages, consider lazy mode
2. **Monitor Disk I/O**: Ensure your RabbitMQ server has fast disk I/O for lazy queues
3. **Combine with TTL**: Use message TTL to prevent queues from growing indefinitely
4. **Test Performance**: Benchmark your specific use case to determine if lazy mode is beneficial

## References

- [RabbitMQ Lazy Queues Documentation](https://www.rabbitmq.com/docs/lazy-queues)
- [RabbitMQ Queue Arguments](https://www.rabbitmq.com/docs/queues#arguments)

## Related Features

- [Maximum Queue Length](./README.md#maximum-queue-length) - Limit queue size
- [Message TTL](./README.md#message-ttl) - Set message expiration
- [Dead Letter Exchange](./README.md#dead-letter-exchange) - Handle rejected messages

