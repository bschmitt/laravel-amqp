# Quorum Queues Documentation

## Overview

Quorum queues are a modern queue type in RabbitMQ that provide high availability, strong consistency, and better performance than mirrored classic queues. They use the Raft consensus algorithm for leader election and replication.

**Status:**  **Supported** via `x-queue-type: quorum`

## Key Features

###  Supported Features

- **Queue Type Selection:** Configure via `x-queue-type: quorum`
- **Automatic Leader Election:** Handled by RabbitMQ automatically
- **Raft Consensus:** Built into RabbitMQ, no manual configuration needed
- **Replication:** Automatic replication across cluster nodes
- **High Availability:** Better than mirrored classic queues
- **Performance:** Improved performance over mirrored queues

### How It Works

Quorum queues use the Raft consensus algorithm internally:
1. **Leader Election:** RabbitMQ automatically elects a leader node
2. **Replication:** Messages are replicated to a majority of nodes
3. **Consensus:** Operations require consensus from majority of nodes
4. **Failover:** Automatic failover if leader node fails

**Note:** Leader election and Raft consensus are handled automatically by RabbitMQ. You don't need to configure them manually - just set `x-queue-type: quorum`.

## Requirements

- **RabbitMQ Version:** 3.8.0 or higher
- **Cluster Setup:** Works best in a multi-node cluster (minimum 3 nodes recommended)
- **Queue Properties:**
  - Must be durable (`queue_durable => true`)
  - Cannot be exclusive (`queue_exclusive => false`)
  - Cannot be auto-delete (`queue_auto_delete => false`)

## Configuration

### Basic Configuration

Add `x-queue-type: quorum` to your queue properties in `config/amqp.php`:

```php
'queue_properties' => [
    'x-queue-type' => 'quorum',
],
```

### Complete Example

```php
'properties' => [
    'production' => [
        'queue' => 'orders-queue',
        'queue_durable' => true,        // Required for quorum queues
        'queue_exclusive' => false,     // Required for quorum queues
        'queue_auto_delete' => false,   // Required for quorum queues
        'queue_properties' => [
            'x-queue-type' => 'quorum',
        ],
        // ... other configuration
    ],
],
```

## Usage Examples

### Publishing to Quorum Queue

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Models\Message;

// Configure quorum queue
config(['amqp.properties.default.queue_properties' => [
    'x-queue-type' => 'quorum',
]]);
config(['amqp.properties.default.queue_durable' => true]);
config(['amqp.properties.default.queue_exclusive' => false]);
config(['amqp.properties.default.queue_auto_delete' => false]);

// Publish message
$message = new Message('Order data');
Amqp::publish('routing.key', $message);
```

### Consuming from Quorum Queue

```php
use Bschmitt\Amqp\Facades\Amqp;

// Configure quorum queue
config(['amqp.properties.default.queue_properties' => [
    'x-queue-type' => 'quorum',
]]);
config(['amqp.properties.default.queue_durable' => true]);

// Consume messages
Amqp::consume('orders-queue', function ($msg, $resolver) {
    echo "Received: " . $msg->body . "\n";
    $resolver->acknowledge($msg);
});
```

## Leader Election

### How It Works

Leader election in quorum queues is **automatic** and handled by RabbitMQ:

1. **Initial Election:** When a quorum queue is created, RabbitMQ elects a leader
2. **Leader Responsibilities:** The leader handles all read/write operations
3. **Replication:** Operations are replicated to follower nodes
4. **Failover:** If leader fails, a new leader is automatically elected
5. **Consensus:** Operations require majority consensus

### No Manual Configuration Required

You don't need to configure leader election - it's automatic:

```php
// Just set the queue type - leader election is automatic
'queue_properties' => [
    'x-queue-type' => 'quorum',
],
```

### Monitoring Leader Status

To check which node is the leader, use RabbitMQ Management API or CLI:

```bash
# Using rabbitmqctl
rabbitmqctl list_queues name type leader

# Using Management API
curl -u guest:guest http://localhost:15672/api/queues/%2F/queue-name
```

## Raft Consensus

### How It Works

Raft consensus is built into RabbitMQ's quorum queue implementation:

1. **Consensus Algorithm:** Uses Raft for distributed consensus
2. **Majority Requirement:** Operations require majority of nodes to agree
3. **Automatic:** No manual configuration needed
4. **Consistency:** Ensures strong consistency across cluster

### Benefits

- **Strong Consistency:** All nodes see the same state
- **Fault Tolerance:** Can tolerate minority node failures
- **Automatic Recovery:** Handles node failures gracefully

### No Manual Configuration

Raft consensus is handled automatically by RabbitMQ - you just need to:

1. Set up a RabbitMQ cluster (3+ nodes recommended)
2. Configure `x-queue-type: quorum`
3. RabbitMQ handles the rest automatically

## Replication

### Automatic Replication

Quorum queues automatically replicate messages across cluster nodes:

1. **Write Operations:** Written to leader and replicated to followers
2. **Read Operations:** Can be read from any node (eventually consistent)
3. **Replication Factor:** Determined by cluster size
4. **Durability:** Messages are durable across node failures

### Replication Guarantees

- **Majority Replication:** Messages replicated to majority of nodes
- **Durability:** Survives minority node failures
- **Consistency:** Strong consistency guarantees

## Comparison with Classic Queues

| Feature | Classic Queues | Quorum Queues |
|---------|---------------|---------------|
| **HA Mechanism** | Mirroring (deprecated) | Built-in replication |
| **Leader Election** | Manual (via mirroring) | Automatic |
| **Consensus** | Not applicable | Raft consensus |
| **Performance** | Good | Better |
| **Consistency** | Eventual | Strong |
| **Setup Complexity** | Medium | Low |
| **RabbitMQ Version** | All versions | 3.8.0+ |

## Best Practices

### Cluster Setup

1. **Minimum 3 Nodes:** Use at least 3 nodes for quorum queues
2. **Odd Number of Nodes:** Prefer odd numbers (3, 5, 7) for better consensus
3. **Network Stability:** Ensure stable network between nodes
4. **Resource Planning:** Quorum queues use more resources than classic

### Queue Configuration

1. **Always Durable:** Set `queue_durable => true`
2. **Never Exclusive:** Set `queue_exclusive => false`
3. **Never Auto-Delete:** Set `queue_auto_delete => false`
4. **Use Descriptive Names:** Name queues clearly

### Performance Optimization

1. **Cluster Size:** Balance between availability and performance
2. **Message Size:** Consider message size for replication overhead
3. **Batch Operations:** Use batch publishing when possible
4. **Connection Pooling:** Reuse connections to reduce overhead

## Limitations

### Quorum Queue Limitations

1. **Must Be Durable:** Cannot create non-durable quorum queues
2. **Cannot Be Exclusive:** Exclusive queues not supported
3. **Cannot Be Auto-Delete:** Auto-delete queues not supported
4. **No Lazy Queues:** Lazy queue mode not supported
5. **Some Features:** Some advanced features not available

### Compatibility

- **RabbitMQ Version:** Requires 3.8.0 or higher
- **Cluster Required:** Works best in multi-node clusters
- **Feature Compatibility:** Some features not compatible with quorum queues

## Migration from Classic Queues

### Migration Steps

1. **Create New Quorum Queue:**
   ```php
   'queue_properties' => [
       'x-queue-type' => 'quorum',
   ],
   ```

2. **Update Consumers:** Point consumers to new queue
3. **Drain Old Queue:** Process remaining messages from classic queue
4. **Switch Producers:** Point producers to new queue
5. **Delete Old Queue:** Remove classic queue after migration

### Migration Considerations

- **Downtime:** Plan for minimal downtime during migration
- **Message Loss:** Ensure all messages are processed before switching
- **Testing:** Test thoroughly in staging environment first
- **Rollback Plan:** Have a rollback plan if issues occur

## Testing

### Unit Tests

Unit tests verify quorum queue configuration:

```bash
php vendor/bin/phpunit test/Unit/QueueTypeTest.php
```

### Integration Tests

Integration tests verify quorum queues work with real RabbitMQ:

```bash
php vendor/bin/phpunit test/Integration/QueueTypeIntegrationTest.php
```

**Note:** Integration tests require RabbitMQ 3.8.0+ and may require a cluster setup for full testing.

## Troubleshooting

### PRECONDITION_FAILED Errors

**Error:** `PRECONDITION_FAILED - invalid property 'auto-delete' for queue`

**Solution:** Quorum queues cannot be auto-delete:
```php
'queue_auto_delete' => false, // Required for quorum queues
```

**Error:** `PRECONDITION_FAILED - invalid property 'exclusive' for queue`

**Solution:** Quorum queues cannot be exclusive:
```php
'queue_exclusive' => false, // Required for quorum queues
```

### Leader Election Issues

**Problem:** Queue not electing leader

**Solutions:**
1. Ensure cluster has minimum 3 nodes
2. Check network connectivity between nodes
3. Verify RabbitMQ version is 3.8.0+
4. Check cluster health: `rabbitmqctl cluster_status`

### Replication Issues

**Problem:** Messages not replicating

**Solutions:**
1. Verify cluster is healthy
2. Check network connectivity
3. Ensure majority of nodes are available
4. Monitor cluster status

## Monitoring

### Queue Metrics

Monitor quorum queue health:

```bash
# List queues with type and leader
rabbitmqctl list_queues name type leader

# Check queue details
rabbitmqctl list_queues name messages consumers memory leader
```

### Cluster Status

Monitor cluster health:

```bash
# Cluster status
rabbitmqctl cluster_status

# Node status
rabbitmqctl node_health_check
```

## References

- [RabbitMQ Quorum Queues](https://www.rabbitmq.com/docs/quorum-queues)
- [RabbitMQ Raft Consensus](https://www.rabbitmq.com/docs/quorum-queues#raft)
- [RabbitMQ Leader Election](https://www.rabbitmq.com/docs/quorum-queues#leader-election)
- [Quorum Queues vs Classic Queues](https://www.rabbitmq.com/docs/quorum-queues#comparison)

## Summary

Quorum queues are **fully supported** in this package via the `x-queue-type: quorum` configuration. Key features:

 **Queue Type Selection:** Configure via `x-queue-type: quorum`  
 **Leader Election:** Automatic (handled by RabbitMQ)  
 **Raft Consensus:** Automatic (built into RabbitMQ)  
 **Replication:** Automatic across cluster nodes  
 **High Availability:** Better than mirrored classic queues  
 **Performance:** Improved over classic mirrored queues  

**Important:** Leader election and Raft consensus are handled automatically by RabbitMQ - you just need to configure `x-queue-type: quorum` and ensure your queue properties meet the requirements (durable, non-exclusive, non-auto-delete).

