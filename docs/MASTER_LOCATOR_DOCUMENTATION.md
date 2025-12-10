# Master Locator Documentation

## Overview

The `x-queue-master-locator` property allows you to specify the strategy for selecting the master node when creating mirrored queues in a RabbitMQ cluster. This feature is part of RabbitMQ's High Availability (HA) functionality.

**⚠️ Important Note:** This feature is **deprecated** in RabbitMQ. RabbitMQ recommends using **Quorum Queues** instead of mirrored queues for high availability, leader election, replication, and cluster resilience.

## Deprecation Notice

- **Status:** Deprecated
- **Recommended Replacement:** Use Quorum Queues (`x-queue-type: quorum`)
- **Reference:** [RabbitMQ HA Documentation](https://www.rabbitmq.com/docs/ha)

## Supported Values

The `x-queue-master-locator` property accepts the following values:

1. **`min-masters`** - Prefer the node with the minimum number of bound masters
2. **`client-local`** - Prefer the node the client is connected to
3. **`random`** - Random selection of master node

## Configuration

### Basic Configuration

Add `x-queue-master-locator` to your queue properties in `config/amqp.php`:

```php
'queue_properties' => [
    'x-queue-master-locator' => 'min-masters',
],
```

### Example Configurations

#### Using min-masters Strategy

```php
'queue_properties' => [
    'x-queue-master-locator' => 'min-masters',
    'x-ha-policy' => ['S', 'all'], // Required for mirrored queues
],
```

#### Using client-local Strategy

```php
'queue_properties' => [
    'x-queue-master-locator' => 'client-local',
    'x-ha-policy' => ['S', 'all'],
],
```

#### Using random Strategy

```php
'queue_properties' => [
    'x-queue-master-locator' => 'random',
    'x-ha-policy' => ['S', 'all'],
],
```

## Usage Examples

### Publishing with Master Locator

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Models\Message;

// Configure queue with master locator
config(['amqp.properties.default.queue_properties' => [
    'x-queue-master-locator' => 'min-masters',
    'x-ha-policy' => ['S', 'all'],
]]);

// Publish message
$message = new Message('Hello, RabbitMQ!');
Amqp::publish('routing.key', $message);
```

### Consuming with Master Locator

```php
use Bschmitt\Amqp\Facades\Amqp;

// Configure queue with master locator
config(['amqp.properties.default.queue_properties' => [
    'x-queue-master-locator' => 'client-local',
    'x-ha-policy' => ['S', 'all'],
]]);

// Consume messages
Amqp::consume('queue-name', function ($msg, $resolver) {
    echo "Received: " . $msg->body . "\n";
    $resolver->acknowledge($msg);
});
```

## Requirements

### RabbitMQ Setup

1. **Cluster Configuration:** Master locator only works with mirrored queues in a RabbitMQ cluster
2. **HA Policy:** You must configure `x-ha-policy` for mirrored queues
3. **Durable Queues:** Mirrored queues should be durable (`queue_durable => true`)

### Limitations

- **Single Node:** Master locator has no effect on single-node RabbitMQ installations
- **Non-Mirrored Queues:** The property is ignored for non-mirrored queues
- **Modern RabbitMQ:** Newer RabbitMQ versions may ignore this property or require specific HA configurations

## Migration to Quorum Queues

Instead of using mirrored queues with master locator, RabbitMQ recommends using Quorum Queues:

```php
// Old approach (deprecated)
'queue_properties' => [
    'x-queue-master-locator' => 'min-masters',
    'x-ha-policy' => ['S', 'all'],
],

// New approach (recommended)
'queue_properties' => [
    'x-queue-type' => 'quorum',
],
```

### Benefits of Quorum Queues

- ✅ Better performance
- ✅ Stronger consistency guarantees
- ✅ Automatic leader election
- ✅ Better resource utilization
- ✅ Active development and support

## Testing

### Unit Tests

Unit tests verify that the master locator property is correctly passed to `queue_declare`:

```bash
php vendor/bin/phpunit test/Unit/MasterLocatorTest.php
```

### Integration Tests

Integration tests verify the property works with a real RabbitMQ instance:

```bash
php vendor/bin/phpunit test/Integration/MasterLocatorIntegrationTest.php
```

**Note:** Integration tests may pass even if the property is ignored by RabbitMQ (e.g., in single-node setups or non-HA configurations).

## Troubleshooting

### Property Ignored

If the master locator property seems to be ignored:

1. **Check Cluster Setup:** Ensure you're running a RabbitMQ cluster
2. **Verify HA Policy:** Confirm `x-ha-policy` is configured
3. **Check RabbitMQ Version:** Older versions may not support all locator strategies
4. **Consider Quorum Queues:** Migrate to quorum queues for better HA support

### PRECONDITION_FAILED Errors

If you encounter `PRECONDITION_FAILED` errors:

1. **Delete Existing Queue:** Delete the queue and recreate it with the new properties
2. **Check Queue Properties:** Ensure all properties are compatible
3. **Verify Durable Setting:** Mirrored queues should be durable

## References

- [RabbitMQ High Availability Guide](https://www.rabbitmq.com/docs/ha)
- [RabbitMQ Quorum Queues](https://www.rabbitmq.com/docs/quorum-queues)
- [RabbitMQ Mirrored Queues (Deprecated)](https://www.rabbitmq.com/docs/ha)

## Summary

While `x-queue-master-locator` is supported in this package for backward compatibility, **Quorum Queues are the recommended approach** for high availability in modern RabbitMQ deployments. The master locator feature is maintained for legacy systems but should not be used in new projects.

