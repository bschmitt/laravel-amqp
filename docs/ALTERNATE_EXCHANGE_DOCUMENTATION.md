# Alternate Exchange Documentation

## Overview

The `alternate-exchange` property allows you to specify an exchange where messages that cannot be routed to any queue will be sent. This is useful for handling unroutable messages and preventing message loss.

When a message is published to an exchange with an alternate exchange configured, and the message cannot be routed to any queue (no matching bindings), RabbitMQ will automatically route the message to the alternate exchange instead of discarding it.

## Use Cases

- **Dead Letter Handling**: Route unroutable messages to a dedicated exchange for monitoring and processing
- **Message Auditing**: Capture all messages that couldn't be routed for analysis
- **Error Recovery**: Process unroutable messages separately and potentially requeue them
- **Debugging**: Identify routing configuration issues by examining messages in the alternate exchange

## Configuration

### Basic Configuration

Add `alternate-exchange` to your exchange properties in `config/amqp.php`:

```php
'exchange_properties' => [
    'alternate-exchange' => 'unroutable-exchange',
],
```

### Complete Example

```php
'properties' => [
    'production' => [
        'exchange' => 'main-exchange',
        'exchange_type' => 'topic',
        'exchange_properties' => [
            'alternate-exchange' => 'unroutable-exchange',
        ],
        // ... other configuration
    ],
],
```

## Usage Examples

### Publishing with Alternate Exchange

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Models\Message;

// Configure exchange with alternate-exchange
config(['amqp.properties.default.exchange_properties' => [
    'alternate-exchange' => 'unroutable-exchange',
]]);

// Publish message
$message = new Message('Hello, RabbitMQ!');
Amqp::publish('routing.key', $message);
```

### Setting Up Alternate Exchange Infrastructure

Before using alternate-exchange, you need to:

1. **Create the alternate exchange** (typically a fanout exchange)
2. **Create a queue bound to the alternate exchange**
3. **Configure the main exchange with alternate-exchange property**

```php
// Step 1: Create alternate exchange and queue
$alternateConfig = [
    'exchange' => 'unroutable-exchange',
    'exchange_type' => 'fanout',
    'queue' => 'unroutable-queue',
    'routing' => '', // Fanout exchanges ignore routing keys
];

// Step 2: Configure main exchange with alternate-exchange
$mainConfig = [
    'exchange' => 'main-exchange',
    'exchange_type' => 'topic',
    'exchange_properties' => [
        'alternate-exchange' => 'unroutable-exchange',
    ],
];
```

### Consuming from Alternate Exchange

```php
use Bschmitt\Amqp\Facades\Amqp;

// Consume unroutable messages
Amqp::consume('unroutable-queue', function ($msg, $resolver) {
    echo "Unroutable message: " . $msg->body . "\n";
    echo "Original routing key: " . $msg->getRoutingKey() . "\n";
    
    // Process or log the unroutable message
    // You might want to:
    // - Log it for debugging
    // - Send alert notifications
    // - Attempt to requeue with corrected routing key
    
    $resolver->acknowledge($msg);
});
```

## How It Works

1. **Message Published**: A message is published to the main exchange with a routing key
2. **Routing Attempt**: RabbitMQ tries to route the message to queues bound to the exchange
3. **No Match Found**: If no queue matches the routing key, the message is unroutable
4. **Alternate Routing**: Instead of discarding, RabbitMQ routes the message to the alternate exchange
5. **Alternate Processing**: The alternate exchange routes the message to its bound queues

## Important Notes

### Exchange Must Exist First

The alternate exchange **must be declared before** the main exchange that references it. Otherwise, RabbitMQ will return an error.

### Exchange Type Recommendation

- **Fanout Exchange**: Most common choice for alternate exchanges
  - Routes messages to all bound queues
  - Ignores routing keys
  - Simple setup for capturing all unroutable messages

- **Topic Exchange**: Use if you need to route unroutable messages based on patterns
  - More complex but flexible
  - Allows filtering of unroutable messages

### Message Properties Preserved

When a message is routed to the alternate exchange:
- The original message body is preserved
- The original routing key is available via `$msg->getRoutingKey()`
- All message properties are maintained

## Testing

### Unit Tests

Unit tests verify that the alternate-exchange property is correctly passed to `exchange_declare`:

```bash
php vendor/bin/phpunit test/Unit/AlternateExchangeTest.php
```

### Integration Tests

Integration tests verify the feature works with a real RabbitMQ instance:

```bash
php vendor/bin/phpunit test/Integration/AlternateExchangeIntegrationTest.php
```

## Troubleshooting

### PRECONDITION_FAILED Error

If you get a `PRECONDITION_FAILED` error:

1. **Alternate Exchange Doesn't Exist**: Ensure the alternate exchange is created before the main exchange
2. **Exchange Already Exists**: Delete the existing exchange and recreate it with the alternate-exchange property

### Messages Not Appearing in Alternate Exchange

1. **Check Routing**: Verify that messages are actually unroutable (no matching queue bindings)
2. **Verify Configuration**: Ensure `alternate-exchange` is correctly set in `exchange_properties`
3. **Check Exchange Declaration**: Confirm the main exchange was declared with the alternate-exchange property

### Messages Still Being Discarded

- Ensure the alternate exchange exists and is bound to at least one queue
- Verify the alternate exchange is declared before the main exchange
- Check that the alternate exchange name matches exactly (case-sensitive)

## Best Practices

1. **Use Descriptive Names**: Name your alternate exchange clearly (e.g., `unroutable-exchange`, `dead-letter-exchange`)
2. **Monitor Alternate Queue**: Set up monitoring and alerting for messages in the alternate queue
3. **Process Regularly**: Consume and process messages from the alternate queue to prevent accumulation
4. **Log Original Routing Key**: Log the original routing key to help identify routing configuration issues
5. **Separate Processing**: Handle unroutable messages separately from normal message processing

## Example: Complete Setup

```php
// 1. Create alternate exchange and queue
$alternatePublisher = new Publisher($alternateConfig);
$alternatePublisher->setup(); // Creates exchange and queue

// 2. Configure main exchange with alternate-exchange
$mainConfig = [
    'exchange' => 'orders-exchange',
    'exchange_type' => 'topic',
    'exchange_properties' => [
        'alternate-exchange' => 'unroutable-exchange',
    ],
    'queue' => 'orders-queue',
    'routing' => 'order.*',
];

// 3. Publish messages
$publisher = new Publisher($mainConfig);
$publisher->publish('order.created', $message); // Will route to orders-queue
$publisher->publish('invalid.key', $message);    // Will route to unroutable-exchange

// 4. Consume unroutable messages
$consumer = new Consumer([
    'queue' => 'unroutable-queue',
    'exchange' => 'unroutable-exchange',
]);
$consumer->consume('unroutable-queue', function ($msg, $resolver) {
    // Process unroutable message
    error_log("Unroutable: " . $msg->getRoutingKey() . " - " . $msg->body);
    $resolver->acknowledge($msg);
});
```

## References

- [RabbitMQ Alternate Exchange Documentation](https://www.rabbitmq.com/docs/ae)
- [RabbitMQ Exchange Types](https://www.rabbitmq.com/docs/exchanges)
- [RabbitMQ Routing](https://www.rabbitmq.com/docs/routing)

## Summary

The `alternate-exchange` feature provides a robust way to handle unroutable messages, preventing message loss and enabling better debugging and monitoring of your messaging system. By configuring an alternate exchange, you ensure that all messages are captured and can be processed, even when routing configurations don't match.

