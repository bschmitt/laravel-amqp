# Laravel AMQP Package - User Manual

## Table of Contents

1. [Introduction](#introduction)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Basic Usage](#basic-usage)
5. [RPC Pattern](#rpc-pattern)
6. [Queue Management](#queue-management)
7. [Management API](#management-api)
8. [Message Properties](#message-properties)
9. [Advanced Features](#advanced-features)
10. [Troubleshooting](#troubleshooting)
11. [Best Practices](#best-practices)

---

## Introduction

The Laravel AMQP package provides a simple and elegant way to work with RabbitMQ message queues in Laravel applications. This package supports publishing and consuming messages, RPC patterns, queue management, and integration with RabbitMQ's Management HTTP API.

### Key Features

- **Simple API** - Easy-to-use methods for publishing and consuming messages
- **RPC Support** - Built-in request-response pattern support
- **Queue Management** - Programmatic control over queues and exchanges
- **Management API** - Full integration with RabbitMQ Management HTTP API
- **Message Properties** - Support for all standard AMQP message properties
- **Laravel Integration** - Seamless integration with Laravel's service container

---

## Installation

### Requirements

- PHP 7.3+ or PHP 8.0+
- Laravel 6.20+ / Lumen 6.20+
- RabbitMQ 3.x server
- php-amqplib/php-amqplib ^3.0

### Composer Installation

```bash
composer require bschmitt/laravel-amqp
```

### Laravel Setup

The package will auto-register its service provider. Publish the configuration file:

```bash
php artisan vendor:publish --provider="Bschmitt\Amqp\Providers\AmqpServiceProvider"
```

### Lumen Setup

For Lumen, register the service provider in `bootstrap/app.php`:

```php
$app->register(Bschmitt\Amqp\Providers\LumenServiceProvider::class);
```

Then copy the config file manually:

```bash
cp vendor/bschmitt/laravel-amqp/config/amqp.php config/amqp.php
```

---

## Configuration

### Basic Configuration

Edit `config/amqp.php`:

```php
return [
    'use' => env('AMQP_ENV', 'production'),

    'properties' => [
        'production' => [
            'host' => env('AMQP_HOST', 'localhost'),
            'port' => env('AMQP_PORT', 5672),
            'username' => env('AMQP_USER', ''),
            'password' => env('AMQP_PASSWORD', ''),
            'vhost' => env('AMQP_VHOST', '/'),
            'exchange' => env('AMQP_EXCHANGE', 'amq.topic'),
            'exchange_type' => env('AMQP_EXCHANGE_TYPE', 'topic'),
            'exchange_durable' => true,
            'queue_durable' => true,
            'queue_auto_delete' => false,
        ],
    ],
];
```

### Environment Variables

Add to your `.env` file:

```env
AMQP_HOST=localhost
AMQP_PORT=5672
AMQP_USER=guest
AMQP_PASSWORD=guest
AMQP_VHOST=/
AMQP_EXCHANGE=amq.topic
AMQP_EXCHANGE_TYPE=topic
```

### Management API Configuration

To use Management API features, add:

```php
'properties' => [
    'production' => [
        // ... existing config ...
        'management_api_url' => env('AMQP_MANAGEMENT_URL', 'http://localhost:15672'),
        'management_api_user' => env('AMQP_MANAGEMENT_USER', 'guest'),
        'management_api_password' => env('AMQP_MANAGEMENT_PASSWORD', 'guest'),
    ],
],
```

---

## Basic Usage

### Publishing Messages

#### Simple Publish

```php
use Bschmitt\Amqp\Facades\Amqp;

// Publish to default exchange and routing key
Amqp::publish('routing.key', 'Hello World');
```

#### Publish with Custom Properties

```php
Amqp::publish('routing.key', 'Message', [
    'exchange' => 'my-exchange',
    'exchange_type' => 'direct',
    'queue' => 'my-queue',
]);
```

#### Publish with Message Properties

```php
Amqp::publish('routing.key', 'Message', [
    'priority' => 10,
    'correlation_id' => 'unique-id',
    'reply_to' => 'reply-queue',
    'application_headers' => [
        'X-Custom-Header' => 'value'
    ],
]);
```

### Consuming Messages

#### Basic Consume

```php
Amqp::consume('queue-name', function ($message, $resolver) {
    // Process message
    $data = $message->body;
    
    // Acknowledge message
    $resolver->acknowledge($message);
    
    // Stop consuming after processing
    $resolver->stopWhenProcessed();
});
```

#### Consume with Options

```php
Amqp::consume('queue-name', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'exchange' => 'my-exchange',
    'exchange_type' => 'direct',
    'routing' => ['routing.key'],
    'timeout' => 60,
    'message_limit' => 100,
]);
```

#### Rejecting Messages

```php
Amqp::consume('queue-name', function ($message, $resolver) {
    try {
        // Process message
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\Exception $e) {
        // Reject and requeue
        $resolver->reject($message, true);
    }
});
```

---

## RPC Pattern

### Making RPC Calls

The package provides built-in RPC support for request-response patterns.

#### Basic RPC Call

```php
// Make an RPC call
$response = Amqp::rpc('rpc-queue', 'request-data', [], 30);

if ($response !== null) {
    // Process response
    echo "Response: " . $response;
} else {
    // Timeout or no response
    echo "No response received";
}
```

#### RPC with Custom Properties

```php
$response = Amqp::rpc('rpc-queue', $requestData, [
    'exchange' => 'rpc-exchange',
    'exchange_type' => 'direct',
], 30);
```

### Creating RPC Servers

#### Using the reply() Method

```php
// RPC Server - processes requests and sends replies
Amqp::consume('rpc-queue', function ($message, $resolver) {
    // Get request data
    $request = $message->body;
    
    // Process request
    $result = processRequest($request);
    
    // Send reply
    $resolver->reply($message, $result);
    
    // Acknowledge the original request
    $resolver->acknowledge($message);
});
```

#### RPC Server with Error Handling

```php
Amqp::consume('rpc-queue', function ($message, $resolver) {
    try {
        $request = json_decode($message->body, true);
        $result = processRequest($request);
        $resolver->reply($message, json_encode($result));
    } catch (\Exception $e) {
        // Send error response
        $resolver->reply($message, json_encode([
            'error' => $e->getMessage()
        ]));
    }
    $resolver->acknowledge($message);
});
```

### RPC in Production

For production RPC servers, use Laravel Artisan commands:

```php
// app/Console/Commands/RpcServer.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Bschmitt\Amqp\Facades\Amqp;

class RpcServer extends Command
{
    protected $signature = 'amqp:rpc-server';
    protected $description = 'Run RPC server';

    public function handle()
    {
        $this->info('Starting RPC server...');
        
        Amqp::consume('rpc-queue', function ($message, $resolver) {
            $result = $this->processRequest($message->body);
            $resolver->reply($message, $result);
            $resolver->acknowledge($message);
        });
    }
    
    private function processRequest($request)
    {
        // Your processing logic
        return 'Response: ' . $request;
    }
}
```

Run with Supervisor or systemd for production.

---

## Queue Management

### Queue Operations

#### Purge Queue

Remove all messages from a queue:

```php
Amqp::queuePurge('my-queue', [
    'queue' => 'my-queue'
]);
```

#### Delete Queue

```php
// Delete queue (only if unused and empty)
Amqp::queueDelete('my-queue', [
    'queue' => 'my-queue'
], false, false);

// Force delete (even if not empty)
Amqp::queueDelete('my-queue', [
    'queue' => 'my-queue'
], false, false);
```

#### Unbind Queue

```php
Amqp::queueUnbind('my-queue', 'my-exchange', 'routing-key', null, [
    'queue' => 'my-queue',
    'exchange' => 'my-exchange'
]);
```

### Exchange Operations

#### Delete Exchange

```php
Amqp::exchangeDelete('my-exchange', [
    'exchange' => 'my-exchange'
], false);
```

#### Unbind Exchange

```php
Amqp::exchangeUnbind('destination-exchange', 'source-exchange', 'routing-key', null, [
    'exchange' => 'destination-exchange'
]);
```

---

## Management API

### Queue Statistics

Get queue information:

```php
$stats = Amqp::getQueueStats('my-queue', '/');

// Returns:
// [
//     'messages' => 10,
//     'consumers' => 2,
//     'message_bytes' => 1024,
//     ...
// ]
```

### Connection Information

```php
// Get all connections
$connections = Amqp::getConnections();

// Get specific connection
$connection = Amqp::getConnections('connection-name');
```

### Channel Information

```php
// Get all channels
$channels = Amqp::getChannels();

// Get specific channel
$channel = Amqp::getChannels('channel-name');
```

### Node Information

```php
// Get all nodes
$nodes = Amqp::getNodes();

// Get specific node
$node = Amqp::getNodes('node-name');
```

### Policy Management

#### Create Policy

```php
Amqp::createPolicy('my-policy', [
    'pattern' => '^my-queue$',
    'definition' => [
        'max-length' => 1000,
        'max-length-bytes' => 1048576,
    ]
], '/');
```

#### Update Policy

```php
Amqp::updatePolicy('my-policy', [
    'pattern' => '^my-queue$',
    'definition' => [
        'max-length' => 2000,
    ]
], '/');
```

#### Delete Policy

```php
Amqp::deletePolicy('my-policy', '/');
```

#### List Policies

```php
$policies = Amqp::getPolicies();
```

### Feature Flags

```php
// List all feature flags
$flags = Amqp::listFeatureFlags();

// Get specific feature flag
$flag = Amqp::getFeatureFlag('quorum_queue');
```

---

## Message Properties

### Setting Message Properties

```php
Amqp::publish('routing.key', 'Message', [
    // Standard properties
    'priority' => 10,                    // 0-255
    'correlation_id' => 'unique-id',
    'reply_to' => 'reply-queue',
    'message_id' => 'msg-123',
    'timestamp' => time(),
    'type' => 'notification',
    'user_id' => 'user123',
    'app_id' => 'my-app',
    'expiration' => '60000',             // TTL in milliseconds
    'content_type' => 'application/json',
    'content_encoding' => 'utf-8',
    'delivery_mode' => 2,                // 2 = persistent
    
    // Custom headers
    'application_headers' => [
        'X-Custom-Header' => 'value',
        'X-Request-ID' => 'req-123',
    ],
]);
```

### Accessing Message Properties

```php
Amqp::consume('queue', function ($message, $resolver) {
    // Get properties
    $priority = $message->getPriority();
    $correlationId = $message->getCorrelationId();
    $replyTo = $message->getReplyTo();
    $headers = $message->getHeaders();
    $customHeader = $message->getHeader('X-Custom-Header');
    
    // Process message
    $resolver->acknowledge($message);
});
```

---

## Advanced Features

### Listen Method

Auto-create queue and bind to multiple routing keys:

```php
Amqp::listen(['key1', 'key2', 'key3'], function ($message, $resolver) {
    // Handle message from any of the routing keys
    $resolver->acknowledge($message);
}, [
    'exchange' => 'my-exchange',
    'exchange_type' => 'topic',
]);
```

### Connection Configuration

Get configuration for a specific connection:

```php
$config = Amqp::getConnectionConfig('production');
// Returns: ['host' => 'localhost', 'port' => 5672, ...]
```

### Multiple Connections

Use different connections for different operations:

```php
// Use production connection
Amqp::publish('key', 'message', [
    'host' => 'prod-rabbitmq.example.com',
    'port' => 5672,
    'username' => 'prod-user',
    'password' => 'prod-pass',
]);

// Use staging connection
Amqp::publish('key', 'message', [
    'host' => 'staging-rabbitmq.example.com',
    'port' => 5672,
    'username' => 'staging-user',
    'password' => 'staging-pass',
]);
```

### Queue Types

#### Classic Queue (Default)

```php
Amqp::consume('queue', function ($message, $resolver) {
    // Process message
}, [
    'queue_properties' => [
        'x-queue-type' => 'classic',
    ],
]);
```

#### Quorum Queue

```php
Amqp::consume('queue', function ($message, $resolver) {
    // Process message
}, [
    'queue_properties' => [
        'x-queue-type' => 'quorum',
    ],
]);
```

#### Stream Queue

```php
Amqp::consume('queue', function ($message, $resolver) {
    // Process message
}, [
    'queue_properties' => [
        'x-queue-type' => 'stream',
    ],
    'queue_durable' => true, // Required for stream queues
]);
```

### Publisher Confirms

Enable publisher confirms for guaranteed delivery:

```php
$publisher = app('amqp.publisher');
$publisher->enablePublisherConfirms();

$publisher->setAckHandler(function ($message) {
    // Message was acknowledged
});

$publisher->setNackHandler(function ($message) {
    // Message was not acknowledged
});

$publisher->publish('routing.key', 'message');
$publisher->waitForConfirms();
```

### Consumer Prefetch

Control message delivery rate:

```php
Amqp::consume('queue', function ($message, $resolver) {
    // Process message
}, [
    'qos_prefetch_count' => 10,  // Max 10 unacked messages
    'qos_prefetch_size' => 0,    // No size limit
    'qos_a_global' => false,     // Per consumer, not per channel
]);
```

---

## Troubleshooting

### Common Issues

#### Connection Errors

**Problem:** Cannot connect to RabbitMQ

**Solutions:**
- Check RabbitMQ is running: `rabbitmqctl status`
- Verify credentials in `.env`
- Check firewall/network settings
- Ensure RabbitMQ port (5672) is accessible

#### Queue Not Found

**Problem:** `PRECONDITION_FAILED - queue not found`

**Solutions:**
- Ensure queue exists before consuming
- Check queue name spelling
- Verify vhost permissions
- Use `queue_passive => true` to check existence without creating

#### Exchange Type Mismatch

**Problem:** `PRECONDITION_FAILED - inequivalent arg 'exchange_type'`

**Solutions:**
- Use `exchange_passive => true` for existing exchanges
- Match exchange type exactly
- Delete and recreate exchange if needed

#### Message Not Received

**Problem:** Messages published but not consumed

**Solutions:**
- Check routing key matches binding
- Verify queue is bound to exchange
- Check consumer is actively running
- Ensure message is acknowledged

### Debug Mode

Enable debug logging:

```php
// In config/amqp.php or .env
define('APP_DEBUG', true);
```

### Testing Connection

```php
use Bschmitt\Amqp\Facades\Amqp;

try {
    Amqp::publish('test', 'test');
    echo "Connection successful";
} catch (\Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}
```

---

## Best Practices

### 1. Error Handling

Always handle errors in consumers:

```php
Amqp::consume('queue', function ($message, $resolver) {
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

### 2. Idempotency

Make message processing idempotent:

```php
Amqp::consume('queue', function ($message, $resolver) {
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

### 3. Dead Letter Queues

Configure DLQ for failed messages:

```php
Amqp::consume('queue', function ($message, $resolver) {
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

### 4. Production Consumers

Use Artisan commands with process managers:

```php
// app/Console/Commands/ProcessQueue.php
class ProcessQueue extends Command
{
    protected $signature = 'queue:process {queue}';
    
    public function handle()
    {
        Amqp::consume($this->argument('queue'), function ($message, $resolver) {
            // Process message
            $resolver->acknowledge($message);
        });
    }
}
```

Run with Supervisor:

```ini
[program:queue-processor]
command=php /path/to/artisan queue:process my-queue
autostart=true
autorestart=true
```

### 5. Message Serialization

Use JSON for complex data:

```php
// Publishing
Amqp::publish('key', json_encode(['data' => 'value']), [
    'content_type' => 'application/json',
]);

// Consuming
Amqp::consume('queue', function ($message, $resolver) {
    $data = json_decode($message->body, true);
    // Process $data
});
```

### 6. Connection Retry

Implement retry logic for persistent consumers:

```php
$maxRetries = 5;
$retry = 0;

while ($retry < $maxRetries) {
    try {
        Amqp::consume('queue', function ($message, $resolver) {
            // Process message
        });
    } catch (\Exception $e) {
        $retry++;
        sleep(pow(2, $retry)); // Exponential backoff
    }
}
```

---

## Additional Resources

- **GitHub Repository:** https://github.com/bschmitt/laravel-amqp
- **RabbitMQ Documentation:** https://www.rabbitmq.com/documentation.html
- **AMQP Protocol:** https://www.rabbitmq.com/amqp-0-9-1-reference.html

---

## Support

For issues, questions, or contributions:

- **GitHub Issues:** https://github.com/bschmitt/laravel-amqp/issues
- **Documentation:** See `docs/` directory
- **FAQ:** See `docs/FAQ.md`

---

**Last Updated:** December 2025
**Package Version:** 3.1.1

