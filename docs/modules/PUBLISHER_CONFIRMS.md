# Publisher Confirms Documentation

## Overview

Publisher confirms provide a lightweight mechanism to ensure that messages have been successfully received and processed by the RabbitMQ broker. This feature is crucial for applications requiring reliable message delivery guarantees.

**Status:**  **Fully Supported**

## Key Features

###  Supported Features

- **Enable Publisher Confirms:** Enable confirms on the channel
- **Ack Callback Registration:** Register callbacks for successful message delivery
- **Nack Callback Registration:** Register callbacks for failed message delivery
- **Return Callback Registration:** Register callbacks for returned messages
- **Wait for Confirms API:** Wait for pending confirmations
- **Wait for Confirms and Returns API:** Wait for both confirms and returns
- **Configuration Support:** Enable via configuration file
- **Mandatory Flag Support:** Automatic enable when using mandatory flag

## How It Works

Publisher confirms work as follows:

1. **Enable Confirms:** Call `enablePublisherConfirms()` or set `publisher_confirms => true` in config
2. **Publish Messages:** Messages are published normally
3. **Broker Confirmation:** RabbitMQ sends `basic.ack` for successful delivery or `basic.nack` for failures
4. **Callback Execution:** Registered callbacks are executed when confirms are received
5. **Wait for Confirms:** Optionally wait for all pending confirms to complete

## Requirements

- **RabbitMQ Version:** All versions support publisher confirms
- **No Additional Plugins:** Built into RabbitMQ core
- **Channel-Level:** Confirms are enabled per channel

## Configuration

### Basic Configuration

Enable publisher confirms in `config/amqp.php`:

```php
'properties' => [
    'production' => [
        'publisher_confirms' => true,      // Enable publisher confirms
        'wait_for_confirms' => true,       // Wait for confirms after publishing (default: true)
        'publish_timeout' => 30,           // Timeout for waiting for confirms (seconds)
        // ... other configuration
    ],
],
```

### Complete Example

```php
'properties' => [
    'production' => [
        'host' => 'localhost',
        'port' => 5672,
        'username' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
        
        'exchange' => 'amq.topic',
        'exchange_type' => 'topic',
        
        'publisher_confirms' => true,      // Enable confirms
        'wait_for_confirms' => true,       // Wait automatically
        'publish_timeout' => 30,           // 30 second timeout
        
        // ... other configuration
    ],
],
```

## Usage Examples

### Enable Publisher Confirms Programmatically

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Models\Message;

$publisher = Amqp::getPublisher();
$publisher->enablePublisherConfirms();

// Publish message
$message = new Message('Test message');
$publisher->publish('routing.key', $message);

// Wait for confirms
$publisher->waitForConfirms(30);
```

### Register Ack Handler

```php
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Models\Message;

$publisher = new Publisher($config);
$publisher->setup();
$publisher->enablePublisherConfirms();

// Register ack handler
$publisher->setAckHandler(function($msg) {
    echo "Message confirmed: " . $msg->body . "\n";
});

$message = new Message('Test message');
$publisher->publish('routing.key', $message);
$publisher->waitForConfirms(30);
```

### Register Nack Handler

```php
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Models\Message;

$publisher = new Publisher($config);
$publisher->setup();
$publisher->enablePublisherConfirms();

// Register nack handler
$publisher->setNackHandler(function($msg) {
    echo "Message rejected: " . $msg->body . "\n";
    // Handle failure (e.g., retry, log, etc.)
});

$message = new Message('Test message');
$publisher->publish('routing.key', $message);
$publisher->waitForConfirms(30);
```

### Register Return Handler

```php
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Models\Message;

$publisher = new Publisher($config);
$publisher->setup();
$publisher->enablePublisherConfirms();

// Register return handler (for mandatory messages)
$publisher->setReturnHandler(function($msg) {
    echo "Message returned: " . $msg->body . "\n";
    // Handle unroutable message
});

// Publish with mandatory flag
$message = new Message('Test message');
$publisher->publish('routing.key', $message, true); // mandatory = true
$publisher->waitForConfirmsAndReturns(30);
```

### Using Configuration

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Models\Message;

// Configure in config/amqp.php:
// 'publisher_confirms' => true,
// 'wait_for_confirms' => true,

// Publisher confirms are automatically enabled
$message = new Message('Test message');
Amqp::publish('routing.key', $message);
// Confirms are automatically waited for
```

### Manual Wait for Confirms

```php
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Models\Message;

$publisher = new Publisher($config);
$publisher->setup();
$publisher->enablePublisherConfirms();

// Configure to not wait automatically
config(['amqp.properties.default.wait_for_confirms' => false]);

// Publish multiple messages
for ($i = 1; $i <= 10; $i++) {
    $message = new Message("Message {$i}");
    $publisher->publish('routing.key', $message);
}

// Wait for all confirms at once
$result = $publisher->waitForConfirms(30);
if ($result) {
    echo "All messages confirmed\n";
} else {
    echo "Timeout or error waiting for confirms\n";
}
```

## API Reference

### enablePublisherConfirms()

Enable publisher confirms on the channel.

```php
$publisher->enablePublisherConfirms();
```

**Returns:** `void`

**Note:** Safe to call multiple times - will only enable once.

### disablePublisherConfirms()

Disable publisher confirms (marks as disabled, but php-amqplib doesn't support disabling).

```php
$publisher->disablePublisherConfirms();
```

**Returns:** `void`

### setAckHandler(callable $callback)

Register a callback for ack confirmations.

```php
$publisher->setAckHandler(function($msg) {
    // Handle successful confirmation
});
```

**Parameters:**
- `callable $callback` - Function to call when message is acked

**Returns:** `void`

### setNackHandler(callable $callback)

Register a callback for nack confirmations.

```php
$publisher->setNackHandler(function($msg) {
    // Handle failed confirmation
});
```

**Parameters:**
- `callable $callback` - Function to call when message is nacked

**Returns:** `void`

### setReturnHandler(callable $callback)

Register a callback for return messages (unroutable messages when mandatory=true).

```php
$publisher->setReturnHandler(function($msg) {
    // Handle returned message
});
```

**Parameters:**
- `callable $callback` - Function to call when message is returned

**Returns:** `void`

### waitForConfirms(?int $timeout = null)

Wait for pending publisher confirms.

```php
$result = $publisher->waitForConfirms(30);
```

**Parameters:**
- `?int $timeout` - Timeout in seconds (null uses default from config)

**Returns:** `bool` - True if all confirms received, false on timeout or error

**Throws:** `RuntimeException` if confirms are not enabled

### waitForConfirmsAndReturns(?int $timeout = null)

Wait for pending publisher confirms and returns.

```php
$result = $publisher->waitForConfirmsAndReturns(30);
```

**Parameters:**
- `?int $timeout` - Timeout in seconds (null uses default from config)

**Returns:** `bool` - True if all confirms received, false on timeout or error

**Throws:** `RuntimeException` if confirms are not enabled

### isConfirmsEnabled()

Check if publisher confirms are enabled.

```php
$enabled = $publisher->isConfirmsEnabled();
```

**Returns:** `bool` - True if confirms are enabled

## Mandatory Flag Integration

When publishing with `mandatory => true`, publisher confirms are automatically enabled if not already enabled. This maintains backward compatibility with existing code.

```php
// Confirms are automatically enabled when mandatory=true
$publisher->publish('routing.key', $message, true);
```

## Best Practices

### When to Use Publisher Confirms

1. **Critical Messages:** Use for messages that must be delivered
2. **Reliability Requirements:** When you need delivery guarantees
3. **Error Handling:** When you need to know if publishing failed
4. **High-Value Operations:** For important business operations

### Performance Considerations

1. **Batch Publishing:** Publish multiple messages, then wait once
2. **Async Processing:** Use callbacks for async processing
3. **Timeout Management:** Set appropriate timeouts
4. **Error Handling:** Always handle nack and return callbacks

### Error Handling

```php
$publisher->setNackHandler(function($msg) {
    // Log the failure
    \Log::error('Message publish failed', ['body' => $msg->body]);
    
    // Retry logic
    // Store for later retry
    // Notify monitoring system
});

$publisher->setReturnHandler(function($msg) {
    // Handle unroutable message
    \Log::warning('Message returned (unroutable)', ['body' => $msg->body]);
    
    // Handle routing issue
    // Update routing logic
    // Notify administrators
});
```

## Comparison with Other Reliability Features

| Feature | Publisher Confirms | Transactions | Mandatory Flag |
|---------|------------------|--------------|----------------|
| **Purpose** | Delivery confirmation | Atomic operations | Unroutable detection |
| **Performance** | Lightweight | Heavy (slower) | Lightweight |
| **Use Case** | Reliable publishing | Multi-message atomicity | Routing validation |
| **Overhead** | Low | High | Low |

## Limitations

### Publisher Confirms Limitations

1. **Channel-Level:** Confirms are per-channel, not per-message
2. **No Message Ordering:** Confirms may arrive out of order
3. **Timeout Required:** Must set appropriate timeouts
4. **Not Transactional:** Not the same as transactions

### Compatibility

- **All RabbitMQ Versions:** Works with all RabbitMQ versions
- **No Plugins Required:** Built into RabbitMQ core
- **Backward Compatible:** Existing code continues to work

## Testing

### Unit Tests

Unit tests verify publisher confirms configuration and callbacks:

```bash
php vendor/bin/phpunit test/Unit/PublisherConfirmsTest.php
```

### Integration Tests

Integration tests verify publisher confirms work with real RabbitMQ:

```bash
php vendor/bin/phpunit test/Integration/PublisherConfirmsIntegrationTest.php
```

## Troubleshooting

### Confirms Not Received

**Problem:** `waitForConfirms()` returns false or times out

**Solutions:**
1. Check RabbitMQ broker is running
2. Verify network connectivity
3. Increase timeout value
4. Check for broker errors in logs
5. Verify exchange and queue exist

### Callbacks Not Called

**Problem:** Registered callbacks are not executed

**Solutions:**
1. Ensure confirms are enabled before publishing
2. Call `waitForConfirms()` after publishing
3. Check that callbacks are registered before publishing
4. Verify message was actually published

### Performance Issues

**Problem:** Publishing is slow with confirms enabled

**Solutions:**
1. Use batch publishing (publish multiple, wait once)
2. Set `wait_for_confirms => false` and wait manually
3. Use async callbacks instead of blocking wait
4. Consider if confirms are necessary for all messages

## References

- [RabbitMQ Publisher Confirms](https://www.rabbitmq.com/docs/confirms)
- [Reliable Publishing](https://www.rabbitmq.com/docs/reliability)
- [php-amqplib Confirms](https://github.com/php-amqplib/php-amqplib)

## Summary

Publisher confirms are **fully supported** in this package. Key features:

 **Enable Publisher Confirms:** Via config or programmatically  
 **Ack Callback Registration:** Handle successful confirmations  
 **Nack Callback Registration:** Handle failed confirmations  
 **Return Callback Registration:** Handle returned messages  
 **Wait for Confirms API:** `waitForConfirms()` and `waitForConfirmsAndReturns()`  
 **Configuration Support:** Enable via `publisher_confirms => true`  
 **Mandatory Flag Integration:** Automatically enables confirms when `mandatory=true`  

**Important:** Publisher confirms provide delivery guarantees and are essential for reliable message publishing. Use them for critical messages that must be delivered.

