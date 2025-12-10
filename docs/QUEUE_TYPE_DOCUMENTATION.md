# Queue Type Documentation

## Overview

RabbitMQ supports different queue types, each optimized for specific use cases. The `x-queue-type` property allows you to specify which type of queue to create.

## Feature Support

✅ **Fully Supported** - The `x-queue-type` property is supported through the `queue_properties` configuration.

## Queue Types

### Classic Queues (Default)

- **Type:** `classic`
- **Description:** Traditional RabbitMQ queues
- **Use Cases:**
  - General purpose messaging
  - Single-node deployments
  - When you don't need replication guarantees
- **Limitations:**
  - Mirroring required for HA (not as efficient as quorum)
  - Performance can degrade with large backlogs

### Quorum Queues

- **Type:** `quorum`
- **Description:** Distributed queues with consensus-based replication
- **Requirements:** RabbitMQ 3.8.0+
- **Use Cases:**
  - High availability requirements
  - Multi-node clusters
  - When you need strong consistency guarantees
  - Better performance than mirrored classic queues
- **Features:**
  - Automatic leader election
  - Built-in replication
  - Better performance than mirrored queues
  - No need for mirroring policies
- **Limitations:**
  - Must be durable
  - Cannot be exclusive
  - Cannot be auto-delete
  - Some features not supported (e.g., lazy queues)

### Stream Queues

- **Type:** `stream`
- **Description:** Append-only log data structure
- **Requirements:** RabbitMQ 3.9.0+
- **Use Cases:**
  - Event sourcing
  - Message replay
  - Large message volumes
  - When you need to read messages multiple times
- **Features:**
  - High throughput
  - Message replay capability
  - Efficient for large volumes
  - Can be consumed multiple times
- **Limitations:**
  - Must be durable
  - Cannot be exclusive
  - Cannot be auto-delete
  - Different consumption model

## Configuration

### Basic Configuration

Add `x-queue-type` to your `queue_properties` in the AMQP configuration:

```php
'queue_properties' => [
    'x-queue-type' => 'quorum'  // or 'classic', 'stream'
]
```

### Configuration Options

- **`classic`**: Traditional RabbitMQ queues (default if not specified)
- **`quorum`**: Distributed queues with consensus-based replication (RabbitMQ 3.8.0+)
- **`stream`**: Append-only log queues (RabbitMQ 3.9.0+)

### Example Configuration

```php
// config/amqp.php
return [
    'properties' => [
        'production' => [
            // ... other settings ...
            'queue_properties' => [
                'x-queue-type' => 'quorum',
                'x-max-length' => 1000,
                'x-message-ttl' => 60000
            ],
        ],
    ],
];
```

## Code Examples

### Publishing to a Quorum Queue

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Models\Message;

// Configure quorum queue
config(['amqp.properties.production.queue_properties' => [
    'x-queue-type' => 'quorum'
]]);

// Publish message
$message = new Message('Hello from quorum queue', [
    'content_type' => 'text/plain'
]);

Amqp::publish('routing.key', $message);
```

### Consuming from a Stream Queue

```php
use Bschmitt\Amqp\Facades\Amqp;

// Configure stream queue
config(['amqp.properties.production.queue_properties' => [
    'x-queue-type' => 'stream'
]]);

// Consume messages
Amqp::consume('queue-name', function ($message, $resolver) {
    echo "Received: " . $message->body . "\n";
    $resolver->acknowledge($message);
});
```

### Combining with Other Properties

Queue types work with other queue properties (where compatible):

```php
'queue_properties' => [
    'x-queue-type' => 'quorum',
    'x-max-length' => 10000,        // Limit queue size
    'x-message-ttl' => 3600000,     // 1 hour TTL
]
```

**Note:** Some properties may not be compatible with all queue types. For example:
- Quorum queues don't support lazy mode
- Stream queues have different consumption semantics

## Testing

### Unit Tests

Unit tests are available in `test/QueueTypeTest.php`:

- `testQueueDeclareWithQuorumType()` - Tests quorum type configuration
- `testQueueDeclareWithStreamType()` - Tests stream type configuration
- `testQueueDeclareWithClassicType()` - Tests classic type configuration
- `testQueueDeclareWithQueueTypeAndOtherProperties()` - Tests combining with other properties
- `testQueueDeclareWithoutQueueType()` - Tests default behavior (classic)

### Integration Tests

Integration tests are available in `test/QueueTypeIntegrationTest.php`:

- `testQueueDeclareWithClassicType()` - Tests queue declaration with classic type
- `testPublishAndConsumeWithClassicType()` - Tests publishing and consuming with classic type
- `testQueueDeclareWithQuorumType()` - Tests queue declaration with quorum type (skipped if not available)
- `testPublishAndConsumeWithQuorumType()` - Tests publishing and consuming with quorum type
- `testQueueTypeWithOtherProperties()` - Tests queue type combined with max-length

Run tests:

```bash
# Unit tests
php vendor/bin/phpunit test/QueueTypeTest.php

# Integration tests (requires RabbitMQ)
php vendor/bin/phpunit test/QueueTypeIntegrationTest.php
```

## Requirements and Compatibility

### RabbitMQ Version Requirements

- **Classic Queues:** All RabbitMQ versions (default)
- **Quorum Queues:** RabbitMQ 3.8.0 or later
- **Stream Queues:** RabbitMQ 3.9.0 or later

### Queue Type Constraints

#### Quorum Queues
- ✅ Must be durable
- ❌ Cannot be exclusive
- ❌ Cannot be auto-delete
- ❌ Cannot use lazy mode
- ✅ Supports most other queue properties

#### Stream Queues
- ✅ Must be durable
- ❌ Cannot be exclusive
- ❌ Cannot be auto-delete
- ✅ Different consumption model (offset-based)
- ✅ Supports message replay

#### Classic Queues
- ✅ All properties supported
- ✅ Can be non-durable
- ✅ Can be exclusive
- ✅ Can be auto-delete
- ✅ Supports lazy mode

## Best Practices

1. **Choose the Right Type:**
   - Use **classic** for general purpose messaging
   - Use **quorum** for HA requirements in clusters
   - Use **stream** for event sourcing and replay scenarios

2. **Version Compatibility:**
   - Check RabbitMQ version before using quorum/stream queues
   - Tests will skip if queue type is not available

3. **Durability:**
   - Quorum and stream queues must be durable
   - Set `queue_durable => true` when using these types

4. **Performance:**
   - Quorum queues perform better than mirrored classic queues
   - Stream queues excel at high-throughput scenarios

## References

- [RabbitMQ Quorum Queues Documentation](https://www.rabbitmq.com/docs/quorum-queues)
- [RabbitMQ Streams Documentation](https://www.rabbitmq.com/docs/streams)
- [RabbitMQ Queue Arguments](https://www.rabbitmq.com/docs/queues#arguments)

## Related Features

- [Lazy Queues](./LAZY_QUEUES_DOCUMENTATION.md) - Keep messages on disk (classic queues only)
- [Maximum Queue Length](./README.md#maximum-queue-length) - Limit queue size
- [Message TTL](./README.md#message-ttl) - Set message expiration
- [Dead Letter Exchange](./README.md#dead-letter-exchange) - Handle rejected messages

