# Time-to-Live (TTL) Feature Documentation

## Overview

The package now fully supports RabbitMQ Time-to-Live (TTL) features:
- **`x-message-ttl`**: Message expiration time
- **`x-expires`**: Queue expiration time

**Reference:** https://www.rabbitmq.com/docs/ttl

---

## Features

### 1. Message TTL (`x-message-ttl`)

Sets the time in milliseconds that a message can remain in the queue before being automatically expired.

**Behavior:**
- Messages older than TTL are automatically discarded
- TTL is checked when message is about to be delivered
- Expired messages are not delivered to consumers
- Value must be a non-negative integer (milliseconds)

### 2. Queue TTL (`x-expires`)

Sets the time in milliseconds that a queue can remain unused before being automatically deleted.

**Behavior:**
- Queue is deleted if unused for the specified time
- "Unused" means: no consumers, no basic.get, no queue.declare
- Queue is deleted even if it contains messages
- Value must be a non-negative integer (milliseconds)

---

## Configuration

### Basic Configuration

Add TTL properties to `queue_properties` in your `config/amqp.php`:

```php
'queue_properties' => [
    'x-message-ttl' => 60000,        // 60 seconds (messages expire after 1 minute)
    'x-expires' => 3600000,         // 1 hour (queue expires after 1 hour of inactivity)
],
```

### Example: Message TTL Only

```php
'queue_properties' => [
    'x-message-ttl' => 30000,       // Messages expire after 30 seconds
],
```

### Example: Queue TTL Only

```php
'queue_properties' => [
    'x-expires' => 1800000,         // Queue expires after 30 minutes of inactivity
],
```

### Example: Both TTL Properties

```php
'queue_properties' => [
    'x-message-ttl' => 60000,       // Messages expire after 60 seconds
    'x-expires' => 3600000,         // Queue expires after 1 hour of inactivity
],
```

### Example: TTL with Other Properties

```php
'queue_properties' => [
    'x-max-length' => 100,          // Maximum 100 messages
    'x-message-ttl' => 60000,       // Messages expire after 60 seconds
    'x-expires' => 3600000,         // Queue expires after 1 hour
    'x-overflow' => 'drop-head',    // Drop oldest when full
],
```

---

## Usage Examples

### Example 1: Temporary Messages Queue

Create a queue where messages expire after 5 minutes:

```php
use Bschmitt\Amqp\Facades\Amqp;

// Publish with custom TTL
Amqp::publish('routing.key', 'message', [
    'queue_properties' => [
        'x-message-ttl' => 300000,  // 5 minutes
    ]
]);
```

### Example 2: Temporary Queue

Create a queue that auto-deletes after 1 hour of inactivity:

```php
use Bschmitt\Amqp\Facades\Amqp;

Amqp::publish('routing.key', 'message', [
    'queue_properties' => [
        'x-expires' => 3600000,     // 1 hour
    ]
]);
```

### Example 3: Using Direct Classes

```php
use Bschmitt\Amqp\Core\Publisher;
use Illuminate\Config\Repository;

$config = [
    'amqp' => [
        'use' => 'production',
        'properties' => [
            'production' => [
                // ... other config ...
                'queue_properties' => [
                    'x-message-ttl' => 60000,
                    'x-expires' => 3600000,
                ],
            ],
        ],
    ],
];

$configRepository = new Repository($config);
$publisher = new Publisher($configRepository);
$publisher->setup();
$publisher->publish('routing.key', 'message');
```

---

## Common TTL Values

| Use Case | Message TTL | Queue TTL | Description |
|----------|-------------|-----------|-------------|
| Short-lived messages | 30000 (30s) | - | Messages expire quickly |
| Medium-lived messages | 300000 (5m) | - | Messages expire after 5 minutes |
| Long-lived messages | 3600000 (1h) | - | Messages expire after 1 hour |
| Temporary queue | - | 1800000 (30m) | Queue deleted after 30 min inactivity |
| Session queue | 1800000 (30m) | 3600000 (1h) | Messages expire, queue auto-deletes |

---

## Testing

### Unit Tests

Run unit tests (no RabbitMQ required):

```bash
php vendor/bin/phpunit test/QueueTTLTest.php
```

**Test Coverage:**
-  `testQueueDeclareWithMessageTTL()` - Message TTL configuration
-  `testQueueDeclareWithQueueExpires()` - Queue expiration configuration
-  `testQueueDeclareWithBothTTLProperties()` - Both properties together
-  `testQueueDeclareWithTTLAndMaxLength()` - TTL with other properties

### Integration Tests

Run integration tests (requires RabbitMQ):

```bash
# Start RabbitMQ
docker-compose up -d rabbit

# Run integration tests
php vendor/bin/phpunit test/QueueTTLIntegrationTest.php
```

**Test Coverage:**
-  `testMessageTTLExpiration()` - Message expiration behavior
-  `testMessageTTLBeforeExpiration()` - Message consumption before TTL
-  `testQueueExpires()` - Queue expiration behavior
-  `testBothTTLPropertiesTogether()` - Combined TTL behavior

---

## Important Notes

### Message TTL

1. **TTL Check Timing**: TTL is checked when a message is about to be delivered, not when it's published
2. **Unacknowledged Messages**: Messages that are delivered but not acknowledged still count towards TTL
3. **Precision**: TTL is not guaranteed to be exact; messages may expire slightly after the TTL period
4. **Zero TTL**: Setting TTL to 0 means messages are immediately expired (not recommended)

### Queue TTL

1. **Unused Definition**: Queue is considered "unused" when:
   - No consumers are attached
   - No `basic.get` operations
   - No `queue.declare` operations
2. **Messages in Queue**: Queue can be deleted even if it contains messages
3. **Recreation**: If queue is deleted and re-declared, it starts fresh
4. **Zero TTL**: Setting TTL to 0 means queue is deleted immediately (not recommended)

### Best Practices

1. **Use Appropriate Values**: Choose TTL values that match your use case
2. **Monitor Expiration**: Monitor expired messages to understand message flow
3. **Combine with DLX**: Consider using Dead Letter Exchange for expired messages
4. **Test Thoroughly**: Test TTL behavior in your environment before production

---

## Troubleshooting

### Messages Not Expiring

**Problem:** Messages are not expiring as expected.

**Solutions:**
- Verify TTL value is set correctly (in milliseconds)
- Check if messages are being consumed before TTL expires
- Ensure queue is not being re-declared (resets TTL)
- Check RabbitMQ server logs for errors

### Queue Not Expiring

**Problem:** Queue is not being deleted after expiration time.

**Solutions:**
- Verify queue is truly "unused" (no consumers, no operations)
- Check if queue is being accessed (prevents expiration)
- Ensure TTL value is set correctly (in milliseconds)
- Check RabbitMQ server logs for errors

### TTL Not Working

**Problem:** TTL properties are not being applied.

**Solutions:**
- Verify properties are in `queue_properties` array
- Check queue is being declared (not using existing queue)
- Ensure properties are passed correctly to RabbitMQ
- Check for syntax errors in configuration

---

## References

- [RabbitMQ TTL Documentation](https://www.rabbitmq.com/docs/ttl)
- [Queue Arguments](https://www.rabbitmq.com/docs/queues#optional-arguments)
- [Message Expiration](https://www.rabbitmq.com/docs/ttl#message-ttl)
- [Queue Expiration](https://www.rabbitmq.com/docs/ttl#queue-ttl)

---

## Changelog

**2024-12-10**: TTL feature fully implemented and tested
-  Added `x-message-ttl` support
-  Added `x-expires` support
-  Created comprehensive unit tests
-  Created comprehensive integration tests
-  Updated documentation

---

**Status:**  Production Ready

