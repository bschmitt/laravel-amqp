# Best Practices

## 1. Error Handling

Always handle errors in consumers:

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\\Exception $e) {
        // Log error
        \\Log::error('Message processing failed', [
            'error' => $e->getMessage(),
            'message' => $message->body,
        ]);
        
        // Reject and requeue (or send to DLQ)
        $resolver->reject($message, true);
    }
});
```

## 2. Idempotency

Make message processing idempotent:

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    $id = $message->getHeader('X-Message-ID');
    
    // Check if already processed
    if (Cache::has("processed:{$id}")) {
        $resolver->acknowledge($message);
        return;
    }
    
    // Process message
    processMessage($message->body);
    
    // Mark as processed
    Cache::put("processed:{$id}", true, 3600);
    
    $resolver->acknowledge($message);
});
```

## 3. Dead Letter Queues

Configure DLQ for failed messages:

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\\Exception $e) {
        // Reject without requeue - goes to DLQ
        $resolver->reject($message, false);
    }
}, [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx',
        'x-dead-letter-routing-key' => 'failed',
    ],
]);
```

For production workloads prefer the declarative pipeline — it wires up the
DLQ, per-delay retry queues, attempt tracking, and CLI flags for you:

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

See [Advanced Features](#advanced) for the full reference.

## 4. Schedule Future Messages

Use `publishLater()` (TTL+DLX by default) for "remind in 60 seconds" style
messages instead of building delay queues by hand:

```php
$amqp = app('Amqp');
$amqp->publishLater('orders.reminder', json_encode(['orderId' => 42]), 60000, [
    'exchange' => 'shop.events',
]);
```

For high-volume deployments enable the `rabbitmq-delayed-message-exchange`
plugin and pass `delay_strategy => 'plugin'`. See
[Delayed Messaging](#delayed-messaging) for the strategy comparison.

## 5. Type Your Messages

Wrap every event in a `TypedMessage` DTO so producers and consumers share a
single source of truth. Bonus: add a static `schema()` method to get
JSON-Schema validation for free.

```php
class OrderCreated extends \\Bschmitt\\Amqp\\Support\\TypedMessage
{
    public $orderId;
    public $total;
    public $currency;

    public function __construct($orderId = null, $total = null, $currency = null)
    {
        $this->orderId = $orderId;
        $this->total = $total;
        $this->currency = $currency;
    }

    public static function routingKey() { return 'orders.created'; }
    public static function exchange()   { return 'shop.events'; }

    public static function schema()
    {
        return [
            'type' => 'object',
            'required' => ['orderId', 'total', 'currency'],
            'properties' => [
                'orderId'  => ['type' => 'string', 'minLength' => 1],
                'total'    => ['type' => 'number', 'minimum' => 0],
                'currency' => ['type' => 'string', 'enum' => ['USD', 'EUR', 'GBP']],
            ],
        ];
    }
}

$amqp->publishTyped(new OrderCreated('order-1', 19.99, 'USD'));
```

Validation failures throw `SchemaValidationException` *before* the publish
ever reaches the broker, so broken payloads never poison a queue.

## 6. Survive Transient Publish Failures

Wrap network-sensitive publishes (HTTP handlers, batch importers) in
`PublishBackoff` so a single TCP blip doesn't blow up your request:

```php
use Bschmitt\\Amqp\\Support\\RetryPolicy;

$amqp->withPublishBackoff(RetryPolicy::exponential(3, 100, 2.0))
    ->run(function () use ($amqp, $payload) {
        return $amqp->publish('orders.created', $payload);
    });
```

## 7. Production Consumers

Use Artisan commands with process managers:

```php
// app/Console/Commands/ProcessQueue.php
class ProcessQueue extends Command
{
    protected $signature = 'queue:process {queue}';
    
    public function handle()
    {
        $amqp = app('Amqp');
        $amqp->consume($this->argument('queue'), function ($message, $resolver) {
            // Process message
            $resolver->acknowledge($message);
        });
    }
}
```

## 8. Monitoring

Monitor queue statistics:

```php
$amqp = app('Amqp');
$stats = $amqp->getQueueStats('my-queue', '/');

if ($stats['messages'] > 1000) {
    // Alert: Queue backlog
}

if ($stats['consumers'] === 0) {
    // Alert: No consumers
}
```
