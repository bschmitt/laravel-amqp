# RPC Pattern

## Overview

The RPC (Request-Response) pattern allows you to make synchronous-like calls over message queues.

## Making RPC Calls

### Simple RPC Call

```php
use Bschmitt\Amqp\Facades\Amqp;

$amqp = app('Amqp');
$response = $amqp->rpc('rpc-queue', 'request-data', [], 30);

if ($response !== null) {
    echo "Response: " . $response;
} else {
    echo "Timeout or no response";
}
```

### RPC with JSON Data

```php
$request = ['action' => 'getUser', 'id' => 123];
$amqp = app('Amqp');
$response = $amqp->rpc('rpc-queue', json_encode($request), [
    'content_type' => 'application/json',
], 30);

$result = json_decode($response, true);
```

## Creating RPC Servers

### Basic RPC Server

```php
$amqp = app('Amqp');
$amqp->consume('rpc-queue', function ($message, $resolver) {
    // Get request
    $request = $message->body;


    // Process request
    $result = processRequest($request);


    // Send reply
    $resolver->reply($message, $result);


    // Acknowledge original request
    $resolver->acknowledge($message);

});
```

### RPC Server with Error Handling

```php
$amqp = app('Amqp');
$amqp->consume('rpc-queue', function ($message, $resolver) {
    try {
        $request = json_decode($message->body, true);

        $result = processRequest($request);


        $resolver->reply($message, json_encode([
            'success' => true,
            'data' => $result

        ]));
    } catch (\Exception $e) {
        $resolver->reply($message, json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]));
    
    }


    $resolver->acknowledge($message);

});
```

## Production RPC Server

```php
// app/Console/Commands/RpcServer.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Bschmitt\Amqp\Facades\Amqp;

class RpcServer extends Command
{

    protected $signature = 'amqp:rpc-server {queue}';
    protected $description = 'Run RPC server';

    public function handle()

    {
        $queue = $this->argument('queue');
        $this->info("Starting RPC server for queue: {$queue}");


        $amqp = app('Amqp');
        $amqp->consume($queue, function ($message, $resolver) {
            try {

                $request = json_decode($message->body, true);
                $result = $this->processRequest($request);


                $resolver->reply($message, json_encode($result));
                $resolver->acknowledge($message);
            } catch (\Exception $e) {
                \Log::error('RPC processing error', [
                    'error' => $e->getMessage(),
                    'request' => $message->body
                ]);


                $resolver->reply($message, json_encode([
                    'error' => $e->getMessage()
                ]));
                $resolver->acknowledge($message);

            }

        });
    
    }


    private function processRequest($request)

    {
        // Your processing logic
        return ['result' => 'processed'];

    }
}
```

## Best Practices

### 1. Always Handle Timeouts

```php
$amqp = app('Amqp');
$response = $amqp->rpc('queue', 'request', [], 30);

if ($response === null) {
    // Handle timeout
    return ['error' => 'Service unavailable'];
}
```

### 2. Use Appropriate Timeouts

```php
// Quick operations: 5-10 seconds
$response = $amqp->rpc('quick-queue', 'request', [], 5);

// Database operations: 10-30 seconds
$response = $amqp->rpc('db-queue', 'request', [], 30);

// Long operations: 60+ seconds
$response = $amqp->rpc('long-queue', 'request', [], 120);
```
