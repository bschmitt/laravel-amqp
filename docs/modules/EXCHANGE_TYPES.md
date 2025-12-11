# Exchange Types Documentation

## Overview

RabbitMQ supports four built-in exchange types, each with different routing behaviors. This package supports all four exchange types with validation to ensure only valid types are used.

## Supported Exchange Types

### 1. Topic Exchange

**Routing Behavior:** Pattern-based routing using routing keys with wildcards.

**Use Cases:**
- Complex routing scenarios
- Multiple consumers with different patterns
- Event-driven architectures
- Log aggregation

**Routing Key Patterns:**
- `*` (star) - Matches exactly one word
- `#` (hash) - Matches zero or more words
- Words separated by dots (`.`)

**Example:**
```php
'exchange_type' => 'topic',
'routing' => 'order.*.created', // Matches: order.user.created, order.admin.created
```

### 2. Direct Exchange

**Routing Behavior:** Exact match routing - routing key must match exactly.

**Use Cases:**
- Simple point-to-point messaging
- Task queues
- Request-response patterns
- Simple routing scenarios

**Example:**
```php
'exchange_type' => 'direct',
'routing' => 'order.created', // Must match exactly
```

### 3. Fanout Exchange

**Routing Behavior:** Broadcasts messages to all bound queues, ignoring routing keys.

**Use Cases:**
- Broadcasting messages to multiple consumers
- Pub/sub patterns
- Event notifications
- Log distribution

**Example:**
```php
'exchange_type' => 'fanout',
'routing' => '', // Routing key is ignored
```

### 4. Headers Exchange

**Routing Behavior:** Routes messages based on message headers instead of routing keys.

**Use Cases:**
- Complex routing based on message attributes
- Multi-criteria routing
- When routing keys are not sufficient

**Example:**
```php
'exchange_type' => 'headers',
'routing' => '', // Routing key is ignored, uses headers instead
```

## Configuration

### Basic Configuration

Set the exchange type in `config/amqp.php`:

```php
'exchange_type' => 'topic', // or 'direct', 'fanout', 'headers'
```

### Complete Example

```php
'properties' => [
    'production' => [
        'exchange' => 'orders-exchange',
        'exchange_type' => 'topic', // Choose: topic, direct, fanout, or headers
        'exchange_durable' => true,
        // ... other configuration
    ],
],
```

## Usage Examples

### Topic Exchange

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Models\Message;

// Configure topic exchange
config(['amqp.properties.default.exchange_type' => 'topic']);

// Publish with pattern-based routing
$message = new Message('Order created');
Amqp::publish('order.user.created', $message); // Matches pattern: order.*.created
```

### Direct Exchange

```php
// Configure direct exchange
config(['amqp.properties.default.exchange_type' => 'direct']);

// Publish with exact routing key match
$message = new Message('Task assigned');
Amqp::publish('task.assigned', $message); // Must match exactly
```

### Fanout Exchange

```php
// Configure fanout exchange
config(['amqp.properties.default.exchange_type' => 'fanout']);

// Publish - routing key is ignored
$message = new Message('Broadcast message');
Amqp::publish('any.key', $message); // All bound queues receive this
```

### Headers Exchange

```php
// Configure headers exchange
config(['amqp.properties.default.exchange_type' => 'headers']);

// Publish with headers
$message = new Message('Header-based message', [
    'content_type' => 'application/json',
    'headers' => [
        'x-priority' => 'high',
        'x-category' => 'order'
    ]
]);
Amqp::publish('', $message); // Routing key ignored, uses headers
```

## Exchange Type Validation

The package automatically validates exchange types to ensure only valid types are used. Invalid types will throw a `Configuration` exception.

### Valid Types

- `topic`
- `direct`
- `fanout`
- `headers`

### Invalid Types

Any other value will result in a `Configuration` exception:

```php
try {
    config(['amqp.properties.default.exchange_type' => 'invalid-type']);
    $publisher = new Publisher($configRepository);
    $publisher->setup();
} catch (Configuration $e) {
    echo "Error: " . $e->getMessage();
    // Output: Invalid exchange type 'invalid-type'. Valid types are: topic, direct, fanout, headers
}
```

## Choosing the Right Exchange Type

### Use Topic Exchange When:
-  You need pattern-based routing
-  Multiple consumers with different patterns
-  Complex routing scenarios
-  Event-driven architectures

### Use Direct Exchange When:
-  Simple point-to-point messaging
-  Exact routing key matching
-  Task queues
-  Request-response patterns

### Use Fanout Exchange When:
-  Broadcasting to all consumers
-  Pub/sub patterns
-  Event notifications
-  Log distribution

### Use Headers Exchange When:
-  Routing based on message attributes
-  Multi-criteria routing
-  Routing keys are not sufficient
-  Complex header-based matching

## Routing Key Behavior by Exchange Type

| Exchange Type | Routing Key Behavior |
|--------------|---------------------|
| **Topic** | Pattern matching with `*` and `#` wildcards |
| **Direct** | Exact match required |
| **Fanout** | Ignored - all bound queues receive message |
| **Headers** | Ignored - uses message headers instead |

## Testing

### Unit Tests

Unit tests verify exchange type validation and declaration:

```bash
php vendor/bin/phpunit test/Unit/ExchangeTypeTest.php
```

### Integration Tests

Integration tests verify exchange types work with a real RabbitMQ instance:

```bash
php vendor/bin/phpunit test/Integration/ExchangeTypeIntegrationTest.php
```

## Troubleshooting

### Invalid Exchange Type Error

**Error:** `Invalid exchange type 'xxx'. Valid types are: topic, direct, fanout, headers`

**Solution:** Ensure `exchange_type` is set to one of the valid types:
- Check your `config/amqp.php` file
- Verify the exchange type is lowercase
- Ensure no typos in the exchange type name

### Exchange Type Mismatch

**Error:** `PRECONDITION_FAILED - inequivalent arg 'type' for exchange`

**Solution:** 
1. Delete the existing exchange
2. Recreate it with the correct type
3. Or use a different exchange name

### Messages Not Routing Correctly

**Topic Exchange:**
- Verify routing key patterns match
- Check wildcard usage (`*` vs `#`)
- Ensure queue bindings use correct patterns

**Direct Exchange:**
- Verify routing keys match exactly
- Check for case sensitivity
- Ensure queue bindings use exact routing keys

**Fanout Exchange:**
- Verify queues are bound to the exchange
- Routing key doesn't matter for fanout
- All bound queues receive all messages

**Headers Exchange:**
- Verify queue bindings use header arguments
- Check message headers match binding criteria
- Ensure headers are set correctly in message properties

## Best Practices

1. **Choose Appropriate Type:** Select the exchange type that best fits your routing needs
2. **Use Descriptive Names:** Name exchanges clearly to indicate their type and purpose
3. **Document Routing Patterns:** Document routing key patterns for topic exchanges
4. **Validate Early:** The package validates exchange types automatically
5. **Test Routing:** Test message routing with different exchange types
6. **Monitor Performance:** Different exchange types have different performance characteristics

## Performance Considerations

- **Topic Exchange:** Moderate performance, supports complex routing
- **Direct Exchange:** Fastest performance, simplest routing
- **Fanout Exchange:** Fast performance, broadcasts to all queues
- **Headers Exchange:** Slower performance, most flexible routing

## References

- [RabbitMQ Exchanges Documentation](https://www.rabbitmq.com/docs/exchanges)
- [RabbitMQ Topic Exchange](https://www.rabbitmq.com/docs/tutorials/tutorial-five)
- [RabbitMQ Direct Exchange](https://www.rabbitmq.com/docs/tutorials/tutorial-four)
- [RabbitMQ Fanout Exchange](https://www.rabbitmq.com/docs/tutorials/tutorial-three)
- [RabbitMQ Headers Exchange](https://www.rabbitmq.com/docs/tutorials/tutorial-five)

## Summary

All four RabbitMQ exchange types are fully supported with automatic validation. Choose the exchange type that best fits your routing requirements:

- **Topic:** Pattern-based routing (most flexible)
- **Direct:** Exact match routing (fastest)
- **Fanout:** Broadcast to all (simplest)
- **Headers:** Header-based routing (most complex)

The package ensures only valid exchange types are used, preventing configuration errors at runtime.

