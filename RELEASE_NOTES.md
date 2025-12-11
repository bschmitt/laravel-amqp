# Release Notes

## Version 3.1.0 - Major Feature Release

This release introduces significant new features, improvements, and bug fixes to the Laravel AMQP package. The package now provides comprehensive support for RabbitMQ management operations, RPC patterns, message properties, and enhanced testing capabilities.

---

## Major New Features

### 1. RPC (Request-Response) Pattern Support

The package now includes built-in support for RPC patterns, making it easy to implement request-response communication between services.

#### New Methods

- **`Amqp::rpc()`** - Make RPC calls with automatic correlation ID and reply queue management
  ```php
  $response = Amqp::rpc('rpc-queue', 'request-data', [], 30);
  ```

- **`Consumer::reply()`** - Send RPC responses from consumer callbacks
  ```php
  Amqp::consume('rpc-queue', function ($message, $resolver) {
      $result = processRequest($message->body);
      $resolver->reply($message, $result);
      $resolver->acknowledge($message);
  });
  ```

- **`Amqp::listen()`** - Convenience method to auto-create queues and bind to multiple routing keys
  ```php
  Amqp::listen(['key1', 'key2'], function ($message, $resolver) {
      // Handle message
  });
  ```

#### Benefits

- Simplified RPC implementation
- Automatic correlation ID management
- Built-in timeout handling
- Support for request-response patterns in microservices

---

### 2. Queue and Exchange Management Operations

Direct programmatic control over RabbitMQ queues and exchanges.

#### New Methods

- **`Amqp::queueUnbind()`** - Unbind a queue from an exchange
- **`Amqp::exchangeUnbind()`** - Unbind an exchange from another exchange
- **`Amqp::queuePurge()`** - Remove all messages from a queue
- **`Amqp::queueDelete()`** - Delete a queue
- **`Amqp::exchangeDelete()`** - Delete an exchange

#### Example Usage

```php
// Purge all messages from a queue
Amqp::queuePurge('my-queue', ['queue' => 'my-queue']);

// Delete a queue
Amqp::queueDelete('my-queue', ['queue' => 'my-queue']);

// Unbind a queue from an exchange
Amqp::queueUnbind('my-queue', 'my-exchange', 'routing-key', [
    'queue' => 'my-queue',
    'exchange' => 'my-exchange'
]);
```

---

### 3. RabbitMQ Management HTTP API Integration

Full integration with RabbitMQ's Management HTTP API for monitoring and statistics.

#### New Methods

- **`Amqp::getQueueStats()`** - Get queue statistics (message count, consumer count, etc.)
- **`Amqp::getConnections()`** - List all active connections
- **`Amqp::getChannels()`** - List all active channels
- **`Amqp::getNodes()`** - Get cluster node information
- **`Amqp::getPolicies()`** - List all policies
- **`Amqp::createPolicy()`** - Create a new policy
- **`Amqp::updatePolicy()`** - Update an existing policy
- **`Amqp::deletePolicy()`** - Delete a policy
- **`Amqp::listFeatureFlags()`** - List all feature flags
- **`Amqp::getFeatureFlag()`** - Get status of a specific feature flag

#### Configuration

Add to your `config/amqp.php`:

```php
'management_api_url' => 'http://localhost:15672',
'management_api_user' => 'guest',
'management_api_password' => 'guest',
```

#### Example Usage

```php
// Get queue statistics
$stats = Amqp::getQueueStats('my-queue', '/');
// Returns: ['messages' => 10, 'consumers' => 2, ...]

// List all connections
$connections = Amqp::getConnections();

// Create a policy
Amqp::createPolicy('my-policy', '/', [
    'pattern' => '^my-queue$',
    'definition' => ['max-length' => 1000]
]);
```

---

### 4. Policy Management

Programmatic management of RabbitMQ policies for queue and exchange configuration.

#### Features

- Create, update, and delete policies
- Support for all policy definition options
- Integration with Management HTTP API

---

### 5. Feature Flags Support

Query RabbitMQ feature flags to determine available capabilities.

#### Methods

- **`Amqp::listFeatureFlags()`** - Get all feature flags and their status
- **`Amqp::getFeatureFlag()`** - Check if a specific feature flag is enabled

---

### 6. Enhanced Message Properties

Full support for standard AMQP message properties.

#### Supported Properties

- **Priority** - Message priority (0-255)
- **Correlation ID** - For RPC patterns
- **Reply-To** - For request-response patterns
- **Message ID** - Unique message identifier
- **Timestamp** - Message timestamp
- **Type** - Message type
- **User ID** - User identifier
- **App ID** - Application identifier
- **Expiration** - Message TTL
- **Content Type** - MIME type
- **Content Encoding** - Content encoding
- **Delivery Mode** - Persistent or transient
- **Application Headers** - Custom headers

#### Example Usage

```php
// Publish with message properties
Amqp::publish('routing-key', 'message', [
    'priority' => 10,
    'correlation_id' => 'unique-id',
    'reply_to' => 'reply-queue',
    'application_headers' => [
        'X-Custom-Header' => 'value'
    ]
]);

// Access properties in consumer
Amqp::consume('queue', function ($message, $resolver) {
    $priority = $message->getPriority();
    $correlationId = $message->getCorrelationId();
    $headers = $message->getHeaders();
});
```

---

### 7. Connection Configuration Helper

New method to retrieve connection configurations programmatically.

#### Method

- **`Amqp::getConnectionConfig()`** - Get configuration for a specific connection

#### Example Usage

```php
$config = Amqp::getConnectionConfig('production');
// Returns: ['host' => 'localhost', 'port' => 5672, ...]
```

---

## Improvements

### Consumer Prefetch (QoS)

- Enhanced prefetch configuration with dynamic adjustment
- Support for `qos_prefetch_count`, `qos_prefetch_size`, and `qos_a_global`
- Better control over message delivery rates

### Publisher Confirms

- Full support for publisher confirms
- Configurable acknowledgment handlers
- Support for `wait_for_confirms` and `publish_timeout`
- Return message handling for unroutable messages

### Queue Types

- Full support for Classic, Quorum, and Stream queue types
- Proper handling of queue type properties
- Validation and error handling

### Exchange Types

- Enhanced validation for exchange types
- Support for custom exchange types (with validation override)
- Better error messages for invalid exchange types

---

## Bug Fixes

### Fixed Issues

1. **Singleton Behavior** - Fixed issue where Publisher and Consumer properties persisted between calls
   - Each call now creates a new instance with merged properties
   - Prevents unexpected routing behavior

2. **Connection Management** - Improved connection and channel cleanup
   - Proper shutdown of connections and channels
   - Better resource management

3. **Configuration Handling** - Enhanced configuration provider
   - Better handling of property merging
   - Improved test environment compatibility

4. **Queue Declaration** - Fixed `PRECONDITION_FAILED` errors
   - Better handling of existing queues with different properties
   - Support for passive queue/exchange declaration

5. **Test Environment** - Improved test reliability
   - Better handling of Laravel facade in test environments
   - Enhanced integration test setup

---

## Documentation

### New Documentation

- Comprehensive developer documentation in wiki format
- Module-by-module feature documentation
- FAQ section addressing common issues
- RPC pattern usage guide
- Testing guide with examples
- Architecture documentation

### Updated Documentation

- Configuration guide with all new options
- Publishing and consuming examples
- Advanced features documentation
- Management API usage guide

---

## Testing

### Test Coverage

- **273 total tests** with comprehensive coverage
- Unit tests for all new features
- Integration tests against real RabbitMQ instances
- Tested with `rabbitmq:3-management` Docker image

### New Test Suites

- RPC method tests
- Management operation tests
- Management API integration tests
- Message properties tests
- Reply method tests

### Test Improvements

- Better test isolation
- Improved cleanup procedures
- Enhanced error handling in tests
- More reliable integration test setup

---

## Backward Compatibility

This release maintains full backward compatibility with previous versions:

- All existing methods continue to work as before
- Configuration file format remains compatible
- Existing code will work without modifications
- New features are opt-in

---

## Dependencies

- PHP 8.1+ (tested with PHP 8.3)
- Laravel 8.x / 9.x / 10.x / 11.x
- php-amqplib/php-amqplib (latest)
- RabbitMQ 3.x (tested with 3-management)

---

## Breaking Changes

**None** - This release is fully backward compatible.

---

## Migration Guide

No migration required. All existing code will continue to work. To use new features:

1. Update your `config/amqp.php` if you want to use Management API features
2. Use new methods as needed in your code
3. Review new documentation for best practices

---

## What's Next

Future improvements planned:

- Enhanced RPC timeout handling
- Better error recovery mechanisms
- Additional queue management operations
- Performance optimizations

---

## Acknowledgments

Special thanks to all contributors and the community for feedback and testing.

---

## Changelog Summary

### Added
- RPC pattern support (`rpc()`, `reply()`, `listen()`)
- Queue and exchange management operations
- Management HTTP API integration
- Policy management
- Feature flags support
- Enhanced message properties
- Connection configuration helper
- Comprehensive test suite
- Developer documentation

### Improved
- Consumer prefetch handling
- Publisher confirms support
- Queue type handling
- Exchange type validation
- Configuration management
- Test reliability
- Error messages

### Fixed
- Singleton behavior issues
- Connection cleanup
- Configuration handling
- Queue declaration errors
- Test environment compatibility

---

## Support

For issues, questions, or contributions, please visit:
- GitHub Issues: [https://github.com/bschmitt/laravel-amqp/issues](https://github.com/bschmitt/laravel-amqp/issues)
- Documentation: See `docs/` directory

---

**Release Date:** 2024
**Version:** 3.1.0
**Status:** Production Ready

