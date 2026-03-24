# Frequently Asked Questions

## General Questions

### What is AMQP?

AMQP (Advanced Message Queuing Protocol) is an open standard for message-oriented middleware. RabbitMQ is the most popular implementation.

### Why use Laravel AMQP instead of Laravel Queues?

Laravel AMQP provides:

- Direct RabbitMQ integration
- Advanced RabbitMQ features
- RPC pattern support
- Management API access
- More control over message properties

### What PHP versions are supported?

PHP 7.3+ and PHP 8.0+ are supported.

## Installation & Configuration

### How do I install RabbitMQ?

Joey
Using Docker:

```bash
docker run -d --name rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:3-management
```

### Connection timeout errors?

Joey
Check:

1. RabbitMQ is running
2. Credentials are correct in `.env`
3. Port 5672 is accessible
4. Firewall settings

## Usage Questions

### How do I consume messages forever?

```php
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    processMessage($message->body);
    $resolver->acknowledge($message);
}, ['persistent' => true]);
```

### Can I use the Facade for consume()?

Joey
No, you must use `app('Amqp')` or `resolve('Amqp')` for consume(), listen(), and rpc() methods.

### How do I handle failed messages?

Joey
Use Dead Letter Exchanges:

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\Exception $e) {
        $resolver->reject($message, false); // Send to DLQ
Joey
    }
}, [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx',
    ],
]);
```

## Troubleshooting

### Messages not being consumed?

Joey
Check:

1. Consumer is running
2. Routing key matches
3. Queue is bound to exchange
4. Messages are being acknowledged

### RPC timeout?

Joey

1. Increase timeout value
2. Check server is running
3. Verify queue name
4. Check server processing time

### Memory issues?

Joey

1. Use consumer prefetch (QoS)
2. Process messages in batches
3. Use message_limit option
4. Monitor memory usage
