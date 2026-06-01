# Frequently Asked Questions

## General Questions

### What is AMQP?

AMQP (Advanced Message Queuing Protocol) is an open standard for message-oriented middleware. RabbitMQ is the most popular implementation.

### Why use Laravel AMQP instead of Laravel Queues?

You don't have to choose — the package **is** a Laravel queue driver. Set
```QUEUE_CONNECTION=amqp``` and use ```dispatch()``` / ```queue:work``` as usual, while
also gaining:

- Direct RabbitMQ integration (exchanges, routing keys, DLX)
- Advanced RabbitMQ features (quorum/stream queues, publisher confirms)
- RPC pattern support
- Management API access
- Fine-grained control over message properties

For low-level publish/consume without Laravel jobs, use ```Amqp::publish()``` and
```app('Amqp')->consume()``` instead.

### How do I use the native Laravel queue driver?

See the [Laravel Queue Driver](#queue-driver) guide. In short:

```env
QUEUE_CONNECTION=amqp
```

```bash
php artisan queue:work amqp --queue=default
```

### What PHP versions are supported?

PHP 7.3 through 8.5 are supported. Use Laravel 8 on PHP 7.3–7.4, Laravel 9 on PHP 8.0.2+, Laravel 10–12 on PHP 8.1+, and Laravel 13 on PHP 8.3+.

## Installation & Configuration

### How do I install RabbitMQ?

Using Docker:
```bash
docker run -d --name rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:3-management
```

### Connection timeout errors?

Check:
1. RabbitMQ is running
2. Credentials are correct in ```.env```
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

No, you must use ```app('Amqp')``` or ```resolve('Amqp')``` for consume(), listen(), and rpc() methods.

### How do I handle failed messages?

For ad-hoc cases, manually configure a dead-letter exchange and
`$resolver->reject($message, false)` to ship the message to the DLQ.

For production workloads use the built-in retry pipeline — it
handles republishing through per-delay retry queues, bumps an
`x-retry-attempt` header, and only forwards to the DLQ once the budget is
exhausted:

```php
use Bschmitt\\Amqp\\Support\\DeadLetterTopology;
use Bschmitt\\Amqp\\Support\\RetryPolicy;

$amqp = app('Amqp');
$topology = DeadLetterTopology::for('orders.process', RetryPolicy::exponential(5, 1000, 2.0, 60000))
    ->on('shop.events', 'topic');

$amqp->declareRetryTopology($topology);
$amqp->consumeWithRetry($topology, function ($message, $resolver) {
    processOrder(json_decode($message->body, true));
    $resolver->acknowledge($message);
});
```

The same pipeline is reachable from the CLI via
`amqp:work --retry=5 --retry-backoff=exponential --declare-topology`.

### How do I schedule a message for later delivery?

Use `publishLater()`:

```php
$amqp = app('Amqp');
$amqp->publishLater('orders.reminder', json_encode(['orderId' => 42]), 60000, [
    'exchange' => 'shop.events',
]);
```

Default strategy creates a per-delay TTL queue that dead-letters to your
real destination — works on stock RabbitMQ. Pass
`delay_strategy => 'plugin'` to use the
`rabbitmq-delayed-message-exchange` plugin instead. See
[Delayed Messaging](#delayed-messaging).

From the CLI: `php artisan amqp:publish order.reminder --body='...' --delay-ms=60000`.

### How do I validate inbound message payloads?

Define a contract DTO with a static `schema()` and consume with
`consumeTyped()` (or `amqp:work --contract=... --validate-schema`):

```php
class OrderCreated extends \\Bschmitt\\Amqp\\Support\\TypedMessage
{
    public $orderId;

    public function __construct($orderId = null) { $this->orderId = $orderId; }

    public static function schema()
    {
        return [
            'type' => 'object',
            'required' => ['orderId'],
            'properties' => [
                'orderId' => ['type' => 'string', 'minLength' => 1],
            ],
        ];
    }
}

$amqp->consumeTyped('orders.queue', OrderCreated::class, function ($order, $message, $resolver) {
    processOrder($order->orderId);
    $resolver->acknowledge($message);
});
```

Schema mismatches raise `Bschmitt\\Amqp\\Exception\\SchemaValidationException`
before your handler runs. See [Schema Validation](#schema-validation) and
[Typed Messaging](#typed-messaging).

## Troubleshooting

### Messages not being consumed?

Check:
1. Consumer is running
2. Routing key matches
3. Queue is bound to exchange
4. Messages are being acknowledged

### RPC timeout?

1. Increase timeout value
2. Check server is running
3. Verify queue name
4. Check server processing time

### Memory issues?

1. Use consumer prefetch (QoS)
2. Process messages in batches
3. Use message_limit option
4. Monitor memory usage
