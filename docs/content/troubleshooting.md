# Troubleshooting

## Connection Errors

### Cannot connect to RabbitMQ

**Problem:** Connection timeout or refused

**Solutions:**

- Check RabbitMQ is running: `rabbitmqctl status`
- Verify credentials in `.env`
- Check firewall/network settings
- Ensure RabbitMQ port (5672) is accessible

### Authentication failed

**Problem:** ACCESS_REFUSED error

**Solutions:**

- Verify username and password
- Check user permissions
- Ensure vhost exists
- Check user has access to vhost

## Queue Errors

### Queue not found

**Problem:** `PRECONDITION_FAILED - queue not found`

**Solutions:**

- Ensure queue exists before consuming
- Check queue name spelling
- Verify vhost permissions
- Use `queue_passive => true` to check existence

### Exchange type mismatch

**Problem:** `PRECONDITION_FAILED - inequivalent arg 'exchange_type'`

**Solutions:**

- Use `exchange_passive => true` for existing exchanges
- Match exchange type exactly
- Delete and recreate exchange if needed

## Message Issues

### Messages not received

**Problem:** Messages published but not consumed

**Solutions:**
Joey

- Check routing key matches binding
- Verify queue is bound to exchange
- Check consumer is actively running
- Ensure message is acknowledged

### Messages disappearing

**Problem:** Messages lost after restart

**Solutions:**
Joey

- Set `delivery_mode => 2` for persistent messages
- Use `queue_durable => true`
- Enable publisher confirms

## Performance Issues

### High memory usage

**Solutions:**
Joey

- Use consumer prefetch (QoS)
- Set `qos_prefetch_count`
- Use `message_limit` option
- Process messages in batches

### Slow processing

**Solutions:**
Joey

- Increase number of consumers
- Optimize message processing
- Use multiple workers
- Consider message priority

## Debug Mode

Enable debug logging:

```php
// In config/amqp.php or .env
define('APP_DEBUG', true);
```

## Testing Connection

```php
use Bschmitt\Amqp\Facades\Amqp;

try {
    Amqp::publish('test', 'test');
    echo "Connection successful";
} catch (\Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}
```
