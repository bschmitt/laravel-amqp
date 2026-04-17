# Best Practices

## 1. Error Handling

Always handle errors in consumers:

```php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\Exception $e) {
        // Log error
        \Log::error('Message processing failed', [
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
    } catch (\Exception $e) {
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

## 4. Production Consumers

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

## 5. Monitoring

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
