# Message Priority Feature Documentation

## Overview

The package now fully supports RabbitMQ Message Priority features:
- **`x-max-priority`**: Maximum priority level for queue (0-255)
- **Message `priority` property**: Priority of individual messages (0-255)

**Reference:** https://www.rabbitmq.com/docs/priority

---

## Features

### 1. Queue Max Priority (`x-max-priority`)

Sets the maximum priority level that the queue supports.

**Behavior:**
- Must be set during queue declaration
- Valid range: 0-255
- Messages with priority > max-priority are treated as max-priority
- If not set, queue does not support priority (all messages treated equally)

### 2. Message Priority Property

Sets the priority of individual messages.

**Behavior:**
- Valid range: 0-255 (but must be <= queue's max-priority)
- Higher priority messages are delivered before lower priority
- Messages without priority are treated as priority 0
- Priority only affects delivery order within the queue

---

## Configuration

### Basic Configuration

Add max priority to `queue_properties` in your `config/amqp.php`:

```php
'queue_properties' => [
    'x-max-priority' => 10,  // Queue supports priorities 0-10
],
```

### Example: Low Priority Queue

```php
'queue_properties' => [
    'x-max-priority' => 5,  // Supports 5 priority levels
],
```

### Example: High Priority Queue

```php
'queue_properties' => [
    'x-max-priority' => 255,  // Full priority range
],
```

### Example: Priority with Other Properties

```php
'queue_properties' => [
    'x-max-priority' => 10,
    'x-max-length' => 100,
    'x-message-ttl' => 60000,
],
```

---

## Usage Examples

### Example 1: Publishing Messages with Priority

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Models\Message;

// High priority message
$highPriorityMessage = new Message('urgent-task', [
    'content_type' => 'text/plain',
    'delivery_mode' => 2,
    'priority' => 10
]);
Amqp::publish('routing.key', $highPriorityMessage, [
    'queue_properties' => [
        'x-max-priority' => 10
    ]
]);

// Low priority message
$lowPriorityMessage = new Message('normal-task', [
    'content_type' => 'text/plain',
    'delivery_mode' => 2,
    'priority' => 1
]);
Amqp::publish('routing.key', $lowPriorityMessage, [
    'queue_properties' => [
        'x-max-priority' => 10
    ]
]);
```

### Example 2: Using Direct Classes

```php
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Models\Message;
use Illuminate\Config\Repository;

$config = [
    'amqp' => [
        'use' => 'production',
        'properties' => [
            'production' => [
                // ... other config ...
                'queue_properties' => [
                    'x-max-priority' => 10,
                ],
            ],
        ],
    ],
];

$configRepository = new Repository($config);
$publisher = new Publisher($configRepository);
$publisher->setup();

// Publish high priority message
$message = new Message('important-data', [
    'content_type' => 'application/json',
    'delivery_mode' => 2,
    'priority' => 10
]);
$publisher->publish('routing.key', $message);
```

### Example 3: Priority-Based Task Processing

```php
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Publisher;

// Setup queue with priority support
$config = [
    'amqp' => [
        'use' => 'production',
        'properties' => [
            'production' => [
                'queue_properties' => [
                    'x-max-priority' => 10,
                ],
            ],
        ],
    ],
];

// Publisher: Send tasks with different priorities
$publisher = new Publisher(new Repository($config));
$publisher->setup();

// Critical task
$critical = new Message('critical-task', [
    'priority' => 10,
    'delivery_mode' => 2
]);
$publisher->publish('tasks', $critical);

// Normal task
$normal = new Message('normal-task', [
    'priority' => 5,
    'delivery_mode' => 2
]);
$publisher->publish('tasks', $normal);

// Low priority task
$low = new Message('low-task', [
    'priority' => 1,
    'delivery_mode' => 2
]);
$publisher->publish('tasks', $low);

// Consumer: Process in priority order
$consumer = new Consumer(new Repository($config));
$consumer->setup();

$consumer->consume('task-queue', function ($message, $resolver) {
    // Process task (high priority tasks processed first)
    processTask($message->body);
    $resolver->acknowledge($message);
});
```

---

## Common Priority Levels

| Priority | Use Case | Description |
|----------|----------|-------------|
| 0 | Default | No priority (default for messages without priority property) |
| 1-3 | Low | Background tasks, non-urgent operations |
| 4-6 | Normal | Standard business operations |
| 7-9 | High | Important tasks, time-sensitive operations |
| 10 | Critical | Urgent tasks, real-time operations |

### Example Priority Scheme

```php
const PRIORITY_CRITICAL = 10;  // System alerts, critical errors
const PRIORITY_HIGH = 8;       // User actions, important updates
const PRIORITY_NORMAL = 5;     // Standard operations
const PRIORITY_LOW = 2;        // Background jobs, reports
const PRIORITY_NONE = 0;       // Default, no priority
```

---

## Priority Behavior

### Delivery Order

Messages are delivered in **descending priority order**:
1. Priority 10 (highest)
2. Priority 9
3. ...
4. Priority 1
5. Priority 0 (lowest, default)

### Same Priority Messages

Messages with the same priority are delivered in **FIFO order** (first in, first out).

### Priority Capping

If a message has priority higher than queue's `x-max-priority`, it's treated as `max-priority`:

```php
// Queue max-priority: 5
// Message priority: 10
// Effective priority: 5 (capped)
```

### Messages Without Priority

Messages without a `priority` property are treated as **priority 0** (lowest).

---

## Testing

### Unit Tests

Run unit tests (no RabbitMQ required):

```bash
php vendor/bin/phpunit test/MessagePriorityTest.php
```

**Test Coverage:**
- ✅ `testQueueDeclareWithMaxPriority()` - Max priority configuration
- ✅ `testQueueDeclareWithPriorityAlias()` - x-priority alias support
- ✅ `testQueueDeclareWithPriorityAndOtherProperties()` - Priority with other properties
- ✅ `testQueueDeclareWithDifferentPriorityLevels()` - Various priority levels

### Integration Tests

Run integration tests (requires RabbitMQ):

```bash
# Start RabbitMQ
docker-compose up -d rabbit

# Run integration tests
php vendor/bin/phpunit test/MessagePriorityIntegrationTest.php
```

**Test Coverage:**
- ✅ `testHighPriorityMessagesConsumedFirst()` - Priority ordering
- ✅ `testMessagesWithoutPriorityTreatedAsZero()` - Default priority behavior
- ✅ `testPriorityExceedingMaxIsCapped()` - Priority capping
- ✅ `testMultiplePriorityLevels()` - Multiple priority levels

---

## Best Practices

### 1. Choose Appropriate Max Priority

```php
// Don't use full range unless needed
'x-max-priority' => 10,  // Good: 10 levels is usually enough

// Avoid
'x-max-priority' => 255,  // Usually unnecessary
```

### 2. Use Consistent Priority Scheme

```php
// Define constants for consistency
class MessagePriority {
    const CRITICAL = 10;
    const HIGH = 8;
    const NORMAL = 5;
    const LOW = 2;
    const NONE = 0;
}

// Use consistently
$message = new Message('data', [
    'priority' => MessagePriority::HIGH
]);
```

### 3. Don't Overuse High Priority

```php
// Bad: Everything is high priority
'priority' => 10  // Defeats the purpose

// Good: Use priority sparingly
'priority' => 10  // Only for truly critical messages
```

### 4. Monitor Priority Distribution

```php
// Track priority usage
logPriorityDistribution($messages);

// Ensure balanced priority usage
// Too many high-priority messages = no priority
```

### 5. Combine with Other Features

```php
'queue_properties' => [
    'x-max-priority' => 10,
    'x-max-length' => 1000,
    'x-message-ttl' => 300000,  // 5 minutes
    // Priority works with other queue features
],
```

---

## Use Cases

### 1. Task Queue with Priorities

```php
// Process urgent tasks first
'x-max-priority' => 10

// Urgent task
$message = new Message('urgent', ['priority' => 10]);

// Normal task
$message = new Message('normal', ['priority' => 5]);
```

### 2. Notification System

```php
// Critical alerts processed first
'x-max-priority' => 5

// Critical alert
$message = new Message('alert', ['priority' => 5]);

// Info notification
$message = new Message('info', ['priority' => 1]);
```

### 3. Order Processing

```php
// VIP orders processed first
'x-max-priority' => 10

// VIP order
$message = new Message('vip-order', ['priority' => 10]);

// Regular order
$message = new Message('order', ['priority' => 5]);
```

### 4. Background Jobs

```php
// Important jobs processed first
'x-max-priority' => 10

// Important job
$message = new Message('important-job', ['priority' => 8]);

// Background job
$message = new Message('background-job', ['priority' => 1]);
```

---

## Important Notes

### Priority Limitations

1. **Queue-Level Only**: Priority only affects order within a single queue
2. **Not Global**: Priority doesn't affect order across different queues
3. **FIFO Within Priority**: Same priority messages are FIFO
4. **No Guarantee**: Priority is best-effort, not guaranteed

### Performance Considerations

1. **Overhead**: Higher max-priority may have slight performance impact
2. **Memory**: More priority levels = more internal structures
3. **Balance**: Too many priority levels may reduce effectiveness

### Compatibility

1. **RabbitMQ Version**: Requires RabbitMQ 3.5.0+
2. **Queue Type**: Works with classic and quorum queues
3. **Backward Compatible**: Queues without priority work normally

---

## Troubleshooting

### Priority Not Working

**Problem:** Messages not being consumed in priority order.

**Solutions:**
- Verify `x-max-priority` is set on queue declaration
- Check message priority property is set correctly
- Ensure queue is re-declared with priority support
- Verify RabbitMQ version supports priority (3.5.0+)

### Priority Exceeding Max

**Problem:** Messages with priority > max-priority.

**Solutions:**
- Messages are automatically capped to max-priority
- Consider increasing `x-max-priority` if needed
- Validate priority before publishing

### All Messages Same Priority

**Problem:** All messages treated as same priority.

**Solutions:**
- Check `x-max-priority` is set correctly
- Verify message priority property is set
- Ensure queue supports priority (not all queue types support it)

---

## References

- [RabbitMQ Priority Queues](https://www.rabbitmq.com/docs/priority)
- [Queue Arguments](https://www.rabbitmq.com/docs/queues#optional-arguments)
- [Message Properties](https://www.rabbitmq.com/docs/producer-confirms)

---

## Changelog

**2024-12-10**: Message Priority feature fully implemented and tested
- ✅ Added `x-max-priority` support
- ✅ Added message `priority` property support
- ✅ Created comprehensive unit tests
- ✅ Created comprehensive integration tests
- ✅ Updated documentation

---

**Status:** ✅ Production Ready

