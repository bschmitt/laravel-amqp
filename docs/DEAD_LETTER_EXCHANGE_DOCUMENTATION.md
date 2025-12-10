# Dead Letter Exchange (DLX) Feature Documentation

## Overview

The package now fully supports RabbitMQ Dead Letter Exchange (DLX) features:
- **`x-dead-letter-exchange`**: Exchange to route dead letters to
- **`x-dead-letter-routing-key`**: Routing key for dead letters (optional)

**Reference:** https://www.rabbitmq.com/docs/dlx

---

## What is Dead Letter Exchange?

A Dead Letter Exchange (DLX) is a special exchange where messages are sent when they cannot be delivered or processed. Messages become "dead letters" when:

1. **Message is rejected** with `requeue=false`
2. **Message expires** (TTL exceeded)
3. **Queue length exceeded** (with `reject-publish-dlx` overflow behavior)
4. **Message cannot be routed** to any queue

---

## Features

### 1. Dead Letter Exchange (`x-dead-letter-exchange`)

Specifies the exchange where dead letters should be sent.

**Behavior:**
- Dead letters are published to this exchange
- Exchange must exist before queue declaration
- If not set, dead letters are discarded

### 2. Dead Letter Routing Key (`x-dead-letter-routing-key`)

Specifies the routing key to use when publishing dead letters.

**Behavior:**
- If set, uses this routing key for dead letters
- If not set, uses the original message's routing key
- Allows routing dead letters to specific queues

---

## Configuration

### Basic Configuration

Add DLX properties to `queue_properties` in your `config/amqp.php`:

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    'x-dead-letter-routing-key' => 'dlx.routing.key',  // Optional
],
```

### Example: DLX Exchange Only

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    // Uses original routing key
],
```

### Example: DLX with Custom Routing Key

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    'x-dead-letter-routing-key' => 'failed.messages',
],
```

### Example: DLX with TTL

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    'x-dead-letter-routing-key' => 'expired.messages',
    'x-message-ttl' => 60000,  // Messages expire after 60 seconds
],
```

### Example: DLX with Max Length

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    'x-dead-letter-routing-key' => 'rejected.messages',
    'x-max-length' => 100,
    'x-overflow' => 'reject-publish-dlx',  // Reject and dead-letter
],
```

### Example: Complete DLX Setup

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    'x-dead-letter-routing-key' => 'dlx.key',
    'x-max-length' => 100,
    'x-message-ttl' => 300000,  // 5 minutes
    'x-overflow' => 'reject-publish-dlx',
],
```

---

## Usage Examples

### Example 1: Basic DLX Setup

```php
use Bschmitt\Amqp\Facades\Amqp;

// Publish with DLX configuration
Amqp::publish('routing.key', 'message', [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx-exchange',
        'x-dead-letter-routing-key' => 'failed',
    ]
]);
```

### Example 2: Handling Rejected Messages

```php
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Publisher;

// Setup queue with DLX
$config = [
    'amqp' => [
        'use' => 'production',
        'properties' => [
            'production' => [
                // ... other config ...
                'queue_properties' => [
                    'x-dead-letter-exchange' => 'dlx-exchange',
                    'x-dead-letter-routing-key' => 'rejected',
                ],
            ],
        ],
    ],
];

$consumer = new Consumer(new \Illuminate\Config\Repository($config));
$consumer->setup();

// Consume and reject messages that fail processing
$consumer->consume('my-queue', function ($message, $resolver) {
    try {
        // Process message
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\Exception $e) {
        // Reject without requeue - goes to DLX
        $resolver->reject($message, false);
    }
});
```

### Example 3: Expired Messages to DLX

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    'x-dead-letter-routing-key' => 'expired',
    'x-message-ttl' => 60000,  // 60 seconds
],
// Expired messages automatically go to DLX
```

### Example 4: Full Error Handling Setup

```php
// Main queue configuration
'main_queue' => [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'error-exchange',
        'x-dead-letter-routing-key' => 'errors.all',
        'x-max-length' => 1000,
        'x-overflow' => 'reject-publish-dlx',
    ],
],

// DLX queue configuration (for consuming dead letters)
'dlx_queue' => [
    'exchange' => 'error-exchange',
    'queue' => 'error-queue',
    'routing' => 'errors.all',
],
```

---

## Common Use Cases

### 1. Error Handling

Route failed messages to a separate queue for analysis:

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'error-exchange',
    'x-dead-letter-routing-key' => 'processing.errors',
],
```

### 2. Message Retry

Route rejected messages to a retry queue:

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'retry-exchange',
    'x-dead-letter-routing-key' => 'retry.queue',
],
```

### 3. Expired Message Handling

Capture expired messages for logging:

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'expired-exchange',
    'x-dead-letter-routing-key' => 'expired.messages',
    'x-message-ttl' => 300000,  // 5 minutes
],
```

### 4. Overflow Handling

Handle queue overflow with DLX:

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'overflow-exchange',
    'x-dead-letter-routing-key' => 'overflow.messages',
    'x-max-length' => 100,
    'x-overflow' => 'reject-publish-dlx',
],
```

---

## When Messages Become Dead Letters

### 1. Message Rejection

```php
// Reject without requeue - goes to DLX
$resolver->reject($message, false);

// Reject with requeue - does NOT go to DLX
$resolver->reject($message, true);
```

### 2. Message Expiration

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    'x-message-ttl' => 60000,  // Expired messages go to DLX
],
```

### 3. Queue Overflow

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    'x-max-length' => 10,
    'x-overflow' => 'reject-publish-dlx',  // Rejected messages go to DLX
],
```

### 4. Maximum Delivery Attempts

Not directly supported, but can be implemented with:
- Message headers (delivery count)
- Consumer logic to reject after N attempts

---

## Testing

### Unit Tests

Run unit tests (no RabbitMQ required):

```bash
php vendor/bin/phpunit test/DeadLetterExchangeTest.php
```

**Test Coverage:**
- ✅ `testQueueDeclareWithDeadLetterExchange()` - DLX exchange configuration
- ✅ `testQueueDeclareWithDeadLetterRoutingKey()` - DLX routing key configuration
- ✅ `testQueueDeclareWithDLXAndOtherProperties()` - DLX with other properties
- ✅ `testQueueDeclareWithDLXExchangeOnly()` - DLX without routing key

### Integration Tests

Run integration tests (requires RabbitMQ):

```bash
# Start RabbitMQ
docker-compose up -d rabbit

# Run integration tests
php vendor/bin/phpunit test/DeadLetterExchangeIntegrationTest.php
```

**Test Coverage:**
- ✅ `testRejectedMessagesGoToDLX()` - Rejected messages routing
- ✅ `testExpiredMessagesGoToDLX()` - Expired messages routing
- ✅ `testMaxLengthMessagesGoToDLX()` - Overflow messages routing
- ✅ `testDLXWithCustomRoutingKey()` - Custom routing key behavior

---

## Best Practices

### 1. Always Create DLX Exchange First

```php
// Step 1: Create DLX exchange
$dlxPublisher = new Publisher($dlxConfig);
$dlxPublisher->setup();

// Step 2: Create queue with DLX reference
$queueConfig = [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx-exchange',
    ],
];
```

### 2. Use Descriptive Routing Keys

```php
'x-dead-letter-routing-key' => 'errors.processing',  // Good
'x-dead-letter-routing-key' => 'dlx',               // Less descriptive
```

### 3. Monitor Dead Letter Queue

```php
// Consume from DLX queue to monitor failures
$dlxConsumer = new Consumer($dlxConfig);
$dlxConsumer->consume('dlx-queue', function ($message, $resolver) {
    logError('Dead letter received: ' . $message->body);
    // Process or archive dead letters
    $resolver->acknowledge($message);
});
```

### 4. Combine with TTL

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    'x-message-ttl' => 300000,  // 5 minutes
    // Expired messages automatically go to DLX
],
```

### 5. Use with Max Length

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    'x-max-length' => 100,
    'x-overflow' => 'reject-publish-dlx',  // Rejected go to DLX
],
```

---

## Troubleshooting

### Dead Letters Not Appearing in DLX

**Problem:** Messages are rejected but not appearing in DLX queue.

**Solutions:**
- Verify DLX exchange exists before queue declaration
- Check DLX routing key matches queue binding
- Ensure messages are rejected with `requeue=false`
- Verify DLX queue is bound to DLX exchange

### Wrong Routing Key in DLX

**Problem:** Dead letters have unexpected routing keys.

**Solutions:**
- Check `x-dead-letter-routing-key` is set correctly
- If not set, DLX uses original message routing key
- Verify DLX queue binding matches routing key

### DLX Exchange Not Found

**Problem:** Queue declaration fails with "exchange not found".

**Solutions:**
- Create DLX exchange before declaring queue
- Verify exchange name matches exactly
- Check exchange exists in same vhost

### Messages Not Dead-Lettered

**Problem:** Messages are rejected but not dead-lettered.

**Solutions:**
- Ensure `requeue=false` when rejecting
- Verify DLX is configured in queue properties
- Check message expiration (TTL) is set correctly
- Verify overflow behavior is `reject-publish-dlx`

---

## Architecture Example

```
┌─────────────┐         ┌──────────────┐         ┌─────────────┐
│   Producer  │────────>│ Main Queue  │────────>│  Consumer   │
└─────────────┘         └──────────────┘         └─────────────┘
                               │
                               │ (reject/expire/overflow)
                               ▼
                        ┌──────────────┐
                        │ DLX Exchange │
                        └──────────────┘
                               │
                               │ (routing key)
                               ▼
                        ┌──────────────┐         ┌─────────────┐
                        │  DLX Queue   │────────>│ DLX Handler│
                        └──────────────┘         └─────────────┘
```

---

## References

- [RabbitMQ Dead Letter Exchange](https://www.rabbitmq.com/docs/dlx)
- [Queue Arguments](https://www.rabbitmq.com/docs/queues#optional-arguments)
- [Message Rejection](https://www.rabbitmq.com/docs/confirms)
- [TTL with DLX](https://www.rabbitmq.com/docs/ttl)

---

## Changelog

**2024-12-10**: Dead Letter Exchange feature fully implemented and tested
- ✅ Added `x-dead-letter-exchange` support
- ✅ Added `x-dead-letter-routing-key` support
- ✅ Created comprehensive unit tests
- ✅ Created comprehensive integration tests
- ✅ Updated documentation

---

**Status:** ✅ Production Ready

