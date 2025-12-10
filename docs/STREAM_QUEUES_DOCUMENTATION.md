# Stream Queues Documentation

## Overview

Stream queues are a specialized queue type in RabbitMQ designed for high-throughput, append-only log data structures. They are ideal for event sourcing, message replay, and scenarios where you need to read messages multiple times.

**Status:** ✅ **Partially Supported** via `x-queue-type: stream`

**Important Note:** Stream queue **declaration** works via AMQP, but **consumption** requires the RabbitMQ Stream API, not standard AMQP `basic_consume`. This package uses the AMQP protocol, so while you can declare and publish to stream queues, consumption may require using RabbitMQ's Stream API directly or a Stream-specific client library.

## Key Features

### ✅ Supported Features

- **Queue Type Selection:** Configure via `x-queue-type: stream` ✅
- **Queue Declaration:** Declare stream queues via AMQP ✅
- **Message Publishing:** Publish messages to stream queues ✅

### ⚠️ Limited Support

- **Message Consumption:** Stream queues require RabbitMQ Stream API, not standard AMQP `basic_consume` ⚠️
  - Queue declaration and publishing work via AMQP
  - Consumption requires Stream API or Stream-specific client
  - This package uses AMQP protocol, so consumption may not work as expected

### Stream Queue Features (Require Stream API)

- **High Throughput:** Optimized for large message volumes
- **Message Replay:** Can be consumed multiple times
- **Append-Only Log:** Messages are stored as an append-only log
- **Offset Management:** Track consumption position (handled by RabbitMQ)
- **Stream Filtering:** Filter messages by criteria (via Stream API)

### How It Works

Stream queues use an append-only log structure:
1. **Append-Only:** Messages are appended to the end of the stream
2. **Multiple Consumers:** Multiple consumers can read from different positions
3. **Offset Tracking:** Each consumer tracks its position in the stream
4. **Replay Capability:** Messages can be replayed from any offset
5. **High Performance:** Optimized for sequential reads and writes

## Requirements

- **RabbitMQ Version:** 3.9.0 or higher
- **Queue Properties:**
  - Must be durable (`queue_durable => true`)
  - Cannot be exclusive (`queue_exclusive => false`)
  - Cannot be auto-delete (`queue_auto_delete => false`)
- **Stream Plugin:** Stream plugin must be enabled (enabled by default in RabbitMQ 3.9.0+)

## Configuration

### Basic Configuration

Add `x-queue-type: stream` to your queue properties in `config/amqp.php`:

```php
'queue_properties' => [
    'x-queue-type' => 'stream',
],
```

### Complete Example

```php
'properties' => [
    'production' => [
        'queue' => 'events-stream',
        'queue_durable' => true,        // Required for stream queues
        'queue_exclusive' => false,     // Required for stream queues
        'queue_auto_delete' => false,   // Required for stream queues
        'queue_properties' => [
            'x-queue-type' => 'stream',
            'x-max-length-bytes' => 1073741824, // Optional: 1GB max size
        ],
        'qos' => true,                  // Required for stream queues
        'qos_prefetch_count' => 10,    // Required for stream queues
        // ... other configuration
    ],
],
```

## Usage Examples

### Publishing to Stream Queue

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Models\Message;

// Configure stream queue
config(['amqp.properties.default.queue_properties' => [
    'x-queue-type' => 'stream',
]]);
config(['amqp.properties.default.queue_durable' => true]);
config(['amqp.properties.default.queue_exclusive' => false]);
config(['amqp.properties.default.queue_auto_delete' => false]);
config(['amqp.properties.default.qos' => true]);
config(['amqp.properties.default.qos_prefetch_count' => 10]);

// Publish message
$message = new Message('Event data');
Amqp::publish('routing.key', $message);
```

### Consuming from Stream Queue

```php
use Bschmitt\Amqp\Facades\Amqp;

// Configure stream queue
config(['amqp.properties.default.queue_properties' => [
    'x-queue-type' => 'stream',
]]);
config(['amqp.properties.default.queue_durable' => true]);

// Consume messages
Amqp::consume('events-stream', function ($msg, $resolver) {
    echo "Received: " . $msg->body . "\n";
    $resolver->acknowledge($msg);
});
```

## Stream Filtering

### Overview

Stream filtering allows you to filter messages based on criteria. This is typically handled at the consumer level using RabbitMQ's Stream API.

### Basic Filtering

Stream filtering is done through consumer configuration:

```php
// Note: Advanced filtering may require direct Stream API usage
// This package supports basic stream queue consumption
$consumer->consume('events-stream', function ($msg, $resolver) {
    // Filter logic in callback
    if (strpos($msg->body, 'important') !== false) {
        // Process important messages
        $resolver->acknowledge($msg);
    } else {
        // Skip or handle differently
        $resolver->acknowledge($msg);
    }
});
```

### Advanced Filtering

For advanced filtering (e.g., by message properties, headers, or content), you may need to use RabbitMQ's Stream API directly or implement filtering logic in your consumer callback.

## Stream Offset Management

### Overview

Offset management tracks the position of each consumer in the stream. RabbitMQ handles this automatically.

### How It Works

1. **Automatic Tracking:** RabbitMQ tracks each consumer's position
2. **Per-Consumer Offsets:** Each consumer has its own offset
3. **Resume Capability:** Consumers can resume from their last position
4. **Multiple Positions:** Multiple consumers can read from different positions

### Offset Management Features

- **Automatic:** Handled by RabbitMQ, no manual configuration needed
- **Per-Consumer:** Each consumer maintains its own offset
- **Persistent:** Offsets are persisted across consumer restarts
- **Flexible:** Consumers can start from beginning, end, or specific offset

### Accessing Offset Information

Offset information can be accessed via RabbitMQ Management API:

```bash
# Using Management API
curl -u guest:guest http://localhost:15672/api/stream/consumers

# Using rabbitmqctl
rabbitmqctl list_stream_consumers
```

## Comparison with Other Queue Types

| Feature | Classic Queues | Quorum Queues | Stream Queues |
|---------|---------------|---------------|---------------|
| **Use Case** | General purpose | High availability | Event sourcing, replay |
| **Consumption** | Once per message | Once per message | Multiple times |
| **Throughput** | Good | Better | Excellent |
| **Replay** | No | No | Yes |
| **Offset Tracking** | No | No | Yes |
| **Multiple Consumers** | Yes (competing) | Yes (competing) | Yes (independent) |
| **RabbitMQ Version** | All versions | 3.8.0+ | 3.9.0+ |

## Best Practices

### Stream Queue Configuration

1. **Always Durable:** Set `queue_durable => true`
2. **Never Exclusive:** Set `queue_exclusive => false`
3. **Never Auto-Delete:** Set `queue_auto_delete => false`
4. **Size Limits:** Consider setting `x-max-length-bytes` for large streams
5. **Use Descriptive Names:** Name streams clearly (e.g., `user-events-stream`)

### Performance Optimization

1. **Batch Publishing:** Use batch operations when possible
2. **Sequential Reads:** Streams are optimized for sequential access
3. **Consumer Groups:** Use multiple consumers for parallel processing
4. **Offset Management:** Let RabbitMQ handle offsets automatically

### Use Cases

1. **Event Sourcing:** Store all events in a stream
2. **Message Replay:** Replay messages for debugging or reprocessing
3. **Audit Logs:** Store audit events in streams
4. **Time-Series Data:** Store time-series data in streams
5. **High-Throughput:** Use streams for high message volumes

## Limitations

### Stream Queue Limitations

1. **Must Be Durable:** Cannot create non-durable stream queues
2. **Cannot Be Exclusive:** Exclusive queues not supported
3. **Cannot Be Auto-Delete:** Auto-delete queues not supported
4. **No Lazy Queues:** Lazy queue mode not supported
5. **Different Consumption Model:** Different from classic/quorum queues
6. **RabbitMQ Version:** Requires 3.9.0 or higher

### Compatibility

- **RabbitMQ Version:** Requires 3.9.0 or higher
- **Stream Plugin:** Must be enabled (enabled by default)
- **Feature Compatibility:** Some features not compatible with stream queues

## Migration from Classic/Quorum Queues

### Migration Steps

1. **Create New Stream Queue:**
   ```php
   'queue_properties' => [
       'x-queue-type' => 'stream',
   ],
   ```

2. **Update Consumers:** Point consumers to new stream queue
3. **Drain Old Queue:** Process remaining messages from old queue
4. **Switch Producers:** Point producers to new stream queue
5. **Delete Old Queue:** Remove old queue after migration

### Migration Considerations

- **Downtime:** Plan for minimal downtime during migration
- **Message Loss:** Ensure all messages are processed before switching
- **Testing:** Test thoroughly in staging environment first
- **Rollback Plan:** Have a rollback plan if issues occur
- **Consumption Model:** Be aware of different consumption semantics

## Testing

### Unit Tests

Unit tests verify stream queue configuration:

```bash
php vendor/bin/phpunit test/Unit/StreamQueueTest.php
```

### Integration Tests

Integration tests verify stream queues work with real RabbitMQ:

```bash
php vendor/bin/phpunit test/Integration/StreamQueueIntegrationTest.php
```

**Note:** Integration tests require RabbitMQ 3.9.0+ with stream plugin enabled.

## Troubleshooting

### PRECONDITION_FAILED Errors

**Error:** `PRECONDITION_FAILED - invalid property 'auto-delete' for queue`

**Solution:** Stream queues cannot be auto-delete:
```php
'queue_auto_delete' => false, // Required for stream queues
```

**Error:** `PRECONDITION_FAILED - invalid property 'exclusive' for queue`

**Solution:** Stream queues cannot be exclusive:
```php
'queue_exclusive' => false, // Required for stream queues
```

### Stream Plugin Not Enabled

**Error:** `NOT_FOUND - no queue 'stream-queue' in vhost '/'`

**Solution:** Ensure stream plugin is enabled:
```bash
rabbitmq-plugins enable rabbitmq_stream
```

### Version Requirements

**Error:** Queue type 'stream' not supported

**Solution:** Ensure RabbitMQ version is 3.9.0 or higher:
```bash
rabbitmqctl version
```

## Monitoring

### Stream Metrics

Monitor stream queue health:

```bash
# List streams
rabbitmqctl list_streams

# Check stream details
rabbitmqctl stream_status stream-name

# List stream consumers
rabbitmqctl list_stream_consumers
```

### Management API

Monitor streams via Management API:

```bash
# Get stream information
curl -u guest:guest http://localhost:15672/api/stream/streams

# Get consumer information
curl -u guest:guest http://localhost:15672/api/stream/consumers
```

## References

- [RabbitMQ Streams](https://www.rabbitmq.com/docs/streams)
- [RabbitMQ Stream Plugin](https://www.rabbitmq.com/docs/streams#plugin)
- [Stream Filtering](https://www.rabbitmq.com/docs/streams#filtering)
- [Stream Offset Management](https://www.rabbitmq.com/docs/streams#offset-management)

## Summary

Stream queues are **partially supported** in this package via the `x-queue-type: stream` configuration. Key features:

✅ **Queue Type Selection:** Configure via `x-queue-type: stream`  
✅ **Queue Declaration:** Declare stream queues via AMQP  
✅ **Message Publishing:** Publish messages to stream queues  

⚠️ **Consumption Limitation:** Stream queue consumption requires RabbitMQ Stream API, not standard AMQP `basic_consume`. This package uses AMQP protocol, so:
- Queue declaration works ✅
- Message publishing works ✅
- Message consumption requires Stream API ⚠️

**Important:** 
- Stream queues require RabbitMQ 3.9.0+ with stream plugin enabled
- Queue declaration and publishing work via AMQP
- Consumption requires RabbitMQ Stream API or Stream-specific client library
- Stream queues are ideal for event sourcing, message replay, and high-throughput scenarios
- For full stream queue functionality (consumption, filtering, offset management), use RabbitMQ's Stream API directly

