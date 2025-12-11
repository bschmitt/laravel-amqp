# Frequently Asked Questions (FAQ)

Got questions? We've got answers! This FAQ covers common issues, questions, and solutions that developers encounter when using the Laravel AMQP package.

## How to Use This FAQ

Feel free to ask questions, and we'll add them here with detailed answers. This FAQ is organized by topic to make it easy to find what you're looking for.

---

## Installation & Setup

### Q: I'm getting a "Service provider not found" error. What's wrong?

**A:** This usually happens when the package isn't properly registered. Here's what to check:

- **Laravel 5.5+**: The package should auto-discover. Try running `composer dump-autoload`
- **Laravel < 5.5**: Make sure you've added the service provider to `config/app.php`
- **Lumen**: Ensure you've called `$app->register(Bschmitt\Amqp\Providers\LumenServiceProvider::class)` in `bootstrap/app.php`

### Q: Configuration file not found. How do I fix this?

**A:** The configuration file needs to exist. Here's how to get it:

- **Laravel**: Run `php artisan vendor:publish --provider="Bschmitt\Amqp\Providers\AmqpServiceProvider"`
- **Lumen**: Manually copy `vendor/bschmitt/laravel-amqp/config/amqp.php` to `config/amqp.php`
- Don't forget to call `$app->configure('amqp')` in Lumen

### Q: How do I set up RabbitMQ for local development?

**A:** The easiest way is using Docker:

```bash
docker run -d --name rabbitmq \
  -p 5672:5672 \
  -p 15672:15672 \
  -e RABBITMQ_DEFAULT_USER=guest \
  -e RABBITMQ_DEFAULT_PASS=guest \
  rabbitmq:3-management
```

This gives you RabbitMQ with the management UI at `http://localhost:15672` (login: guest/guest).

**Note:** All our tests are written for the `rabbitmq:3-management` Docker image, so this setup matches our testing environment perfectly.

---

## Connection Issues

### Q: I'm getting "Connection refused" errors. What should I check?

**A:** Here's a checklist:

1. **Is RabbitMQ running?** Check with `docker ps` or your service manager
2. **Check your host and port** - Default is `localhost:5672`
3. **Verify credentials** - Make sure username/password are correct in your `.env` file
4. **Firewall** - Ensure port 5672 isn't blocked
5. **Virtual host** - Check if you're using the right vhost (default is `/`)

### Q: How do I handle connection failures and retry when RabbitMQ server goes down?

**A:** When running persistent consumers, the RabbitMQ server may go down or reboot, causing connection errors like "Connection reset by peer" or "Broken pipe". The package doesn't have built-in automatic reconnection, but you can implement retry logic in your application.

**The Problem:**

When a persistent consumer is running and RabbitMQ goes down:

```php
Amqp::consume('my-queue', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'persistent' => true,
    'timeout' => 0,  // Run forever
]);
```

You'll get errors like:
- `fwrite(): send of 12 bytes failed with errno=104 Connection reset by peer`
- `fwrite(): send of 19 bytes failed with errno=32 Broken pipe`

**Solution: Implement Retry Logic with Exception Handling**

Wrap your consumer in a retry loop that catches connection errors and reconnects:

```php
use Bschmitt\Amqp\Facades\Amqp;
use PhpAmqpLib\Exception\AMQPConnectionException;
use PhpAmqpLib\Exception\AMQPIOException;

class PersistentConsumer
{
    private $maxRetries = 10;
    private $retryDelay = 5; // seconds
    private $maxRetryDelay = 60; // max delay in seconds

    public function start()
    {
        $retryCount = 0;
        
        while (true) {
            try {
                $this->consume();
                // If we get here, consumer stopped normally
                break;
            } catch (AMQPConnectionException $e) {
                $retryCount++;
                $this->handleConnectionError($e, $retryCount);
            } catch (AMQPIOException $e) {
                $retryCount++;
                $this->handleConnectionError($e, $retryCount);
            } catch (\Exception $e) {
                // Log other errors but don't retry
                \Log::error('Consumer error: ' . $e->getMessage());
                throw $e;
            }
        }
    }

    private function consume()
    {
        Amqp::consume('my-queue', function ($message, $resolver) {
            try {
                // Process your message
                $this->processMessage($message);
                $resolver->acknowledge($message);
            } catch (\Exception $e) {
                \Log::error('Message processing error: ' . $e->getMessage());
                // Requeue for retry
                $resolver->reject($message, true);
            }
        }, [
            'persistent' => true,
            'timeout' => 0,
        ]);
    }

    private function handleConnectionError(\Exception $e, int $retryCount)
    {
        if ($retryCount > $this->maxRetries) {
            \Log::error("Max retries ({$this->maxRetries}) reached. Exiting.");
            throw $e;
        }

        // Exponential backoff
        $delay = min($this->retryDelay * pow(2, $retryCount - 1), $this->maxRetryDelay);
        
        \Log::warning("Connection lost (attempt {$retryCount}/{$this->maxRetries}). Retrying in {$delay} seconds...");
        \Log::warning("Error: " . $e->getMessage());
        
        sleep($delay);
    }

    private function processMessage($message)
    {
        // Your message processing logic
        $data = json_decode($message->body, true);
        // ... process the message
    }
}
```

**Using in Artisan Command:**

```php
// app/Console/Commands/ConsumeWithRetry.php
class ConsumeWithRetry extends Command
{
    protected $signature = 'amqp:consume-retry {queue}';
    protected $description = 'Consume messages with automatic reconnection';

    public function handle()
    {
        $queueName = $this->argument('queue');
        $maxRetries = 10;
        $retryCount = 0;

        while (true) {
            try {
                $this->info("Starting consumer for queue: {$queueName}");
                
                Amqp::consume($queueName, function ($message, $resolver) {
                    try {
                        $this->processMessage($message);
                        $resolver->acknowledge($message);
                    } catch (\Exception $e) {
                        $this->error('Processing error: ' . $e->getMessage());
                        $resolver->reject($message, true);
                    }
                }, [
                    'persistent' => true,
                    'timeout' => 0,
                ]);
                
                // If we exit normally, break
                break;
                
            } catch (\PhpAmqpLib\Exception\AMQPConnectionException $e) {
                $retryCount++;
                $this->handleRetry($e, $retryCount, $maxRetries);
            } catch (\PhpAmqpLib\Exception\AMQPIOException $e) {
                $retryCount++;
                $this->handleRetry($e, $retryCount, $maxRetries);
            }
        }
    }

    private function handleRetry(\Exception $e, int $retryCount, int $maxRetries)
    {
        if ($retryCount > $maxRetries) {
            $this->error("Max retries reached. Exiting.");
            throw $e;
        }

        $delay = min(5 * pow(2, $retryCount - 1), 60); // Exponential backoff, max 60s
        $this->warn("Connection lost. Retrying in {$delay}s (attempt {$retryCount}/{$maxRetries})...");
        sleep($delay);
    }

    private function processMessage($message)
    {
        // Your processing logic
    }
}
```

**Advanced: Using Heartbeat for Early Detection**

Enable heartbeat to detect connection failures faster:

```php
// In config/amqp.php
'connect_options' => [
    'heartbeat' => 60,  // 60 seconds - detect failures faster
    'connection_timeout' => 10.0,
    'read_write_timeout' => 130,  // Should be > 2 * heartbeat
],
```

**Using Supervisor for Automatic Restart**

Configure Supervisor to automatically restart your consumer if it crashes:

```ini
[program:amqp-consumer]
command=php /path/to/artisan amqp:consume my-queue
autostart=true
autorestart=true
startretries=10
stopwaitsecs=3600
user=www-data
```

**Best Practices:**

1. **Enable Heartbeats**: Helps detect connection failures quickly
2. **Use Exponential Backoff**: Avoid overwhelming the server during reconnection
3. **Log Errors**: Track connection issues for monitoring
4. **Set Max Retries**: Prevent infinite retry loops
5. **Handle Message Processing Errors**: Separate connection errors from message processing errors
6. **Use Process Managers**: Supervisor or systemd can restart crashed processes
7. **Monitor Connection Health**: Use Management API to check RabbitMQ status

**Complete Example with Error Handling:**

```php
class RobustConsumer
{
    public function consumeWithRetry($queueName, $callback)
    {
        $maxRetries = 10;
        $retryCount = 0;
        $baseDelay = 5;

        while (true) {
            try {
                Amqp::consume($queueName, function ($message, $resolver) use ($callback) {
                    try {
                        $callback($message, $resolver);
                        $resolver->acknowledge($message);
                    } catch (\Exception $e) {
                        \Log::error('Message processing failed: ' . $e->getMessage());
                        // Requeue for retry
                        $resolver->reject($message, true);
                    }
                }, [
                    'persistent' => true,
                    'timeout' => 0,
                    'connect_options' => [
                        'heartbeat' => 60,  // Detect failures faster
                    ],
                ]);

                // Normal exit
                break;

            } catch (\PhpAmqpLib\Exception\AMQPConnectionException $e) {
                $retryCount = $this->handleConnectionError($e, $retryCount, $maxRetries, $baseDelay);
            } catch (\PhpAmqpLib\Exception\AMQPIOException $e) {
                $retryCount = $this->handleConnectionError($e, $retryCount, $maxRetries, $baseDelay);
            } catch (\ErrorException $e) {
                // Handle "Connection reset by peer" and "Broken pipe"
                if (strpos($e->getMessage(), 'Connection reset') !== false || 
                    strpos($e->getMessage(), 'Broken pipe') !== false) {
                    $retryCount = $this->handleConnectionError($e, $retryCount, $maxRetries, $baseDelay);
                } else {
                    throw $e;
                }
            }
        }
    }

    private function handleConnectionError(\Exception $e, int $retryCount, int $maxRetries, int $baseDelay): int
    {
        $retryCount++;
        
        if ($retryCount > $maxRetries) {
            \Log::error("Max retries reached. Exiting consumer.");
            throw $e;
        }

        $delay = min($baseDelay * pow(2, $retryCount - 1), 60);
        \Log::warning("Connection error (attempt {$retryCount}/{$maxRetries}): " . $e->getMessage());
        \Log::info("Retrying in {$delay} seconds...");
        
        sleep($delay);
        
        return $retryCount;
    }
}
```

**Important Notes:**

1. **Connection Errors**: The package doesn't automatically reconnect - you need to implement retry logic
2. **Message Safety**: Unacknowledged messages are automatically requeued when connection drops
3. **Idempotency**: Make your message processing idempotent (safe to retry) since messages may be redelivered
4. **Heartbeat**: Enable heartbeat to detect failures faster (recommended: 60 seconds)
5. **Process Managers**: Use Supervisor or systemd for automatic process restart

**Reference:** This issue was discussed in [GitHub Issue #21](https://github.com/bschmitt/laravel-amqp/issues/21). The package doesn't have built-in automatic reconnection, but you can implement retry logic in your application code or use process managers like Supervisor for automatic restart.

### Q: How can I configure multiple hosts for a RabbitMQ cluster?

**A:** The Laravel AMQP package connects to a single host at a time. For RabbitMQ clusters, you have several options:

**Option 1: Use a Load Balancer (Recommended)**

Place a load balancer (like HAProxy or Nginx) in front of your RabbitMQ cluster and connect to the load balancer:

```php
// In config/amqp.php or .env
'host' => env('AMQP_HOST', 'rabbitmq-lb.example.com'),  // Load balancer address
'port' => env('AMQP_PORT', 5672),
```

**HAProxy Example Configuration:**

```haproxy
global
    log stdout local0
    maxconn 4096

defaults
    mode tcp
    timeout connect 5000ms
    timeout client 50000ms
    timeout server 50000ms

frontend rabbitmq_frontend
    bind *:5672
    default_backend rabbitmq_backend

backend rabbitmq_backend
    balance roundrobin
    option tcp-check
    tcp-check connect port 5672
    server rabbit1 rabbitmq-node1.example.com:5672 check
    server rabbit2 rabbitmq-node2.example.com:5672 check
    server rabbit3 rabbitmq-node3.example.com:5672 check
```

**Option 2: Use Quorum Queues (Best for High Availability)**

Quorum queues automatically handle replication and failover across cluster nodes. You only need to connect to one node, and RabbitMQ handles the rest:

```php
'queue_properties' => [
    'x-queue-type' => 'quorum',  // Automatic replication across cluster
],
```

With quorum queues, if the node you're connected to fails, RabbitMQ automatically redirects to another node in the cluster.

**Option 3: Application-Level Failover**

Implement connection retry logic in your application:

```php
$hosts = [
    'rabbitmq-node1.example.com',
    'rabbitmq-node2.example.com',
    'rabbitmq-node3.example.com',
];

foreach ($hosts as $host) {
    try {
        Amqp::publish('routing.key', 'message', [
            'host' => $host,
        ]);
        break; // Success, exit loop
    } catch (\Exception $e) {
        \Log::warning("Failed to connect to $host: " . $e->getMessage());
        continue; // Try next host
    }
}
```

**Option 4: Environment-Based Configuration**

Use different configurations for different environments, pointing to different cluster nodes:

```php
// config/amqp.php
'properties' => [
    'production' => [
        'host' => env('AMQP_HOST', 'rabbitmq-prod-lb.example.com'),
    ],
    'staging' => [
        'host' => env('AMQP_HOST', 'rabbitmq-staging-lb.example.com'),
    ],
],
```

**Best Practices for Clusters:**

1. **Use a Load Balancer** - Simplest and most reliable approach
2. **Enable Heartbeats** - Helps detect connection failures quickly:
   ```php
   'connect_options' => [
       'heartbeat' => 60,  // 60 seconds
   ],
   ```
3. **Use Quorum Queues** - Best for high availability (recommended for new setups)
4. **Monitor Cluster Health** - Use Management API to check cluster status:
   ```php
   $nodes = Amqp::getNodes();
   ```
5. **Configure Timeouts** - Set appropriate connection timeouts:
   ```php
   'connect_options' => [
       'connection_timeout' => 10.0,
       'read_write_timeout' => 130,
   ],
   ```

**Important Notes:**
- The package connects to one host at a time (standard AMQP behavior)
- For true high availability, use a load balancer or quorum queues
- All cluster nodes should have the same virtual hosts and user credentials
- Management API can be configured separately if needed:
  ```php
  'management_host' => 'http://rabbitmq-lb.example.com',
  ```

**Reference:** This question was asked in [GitHub Issue #112](https://github.com/bschmitt/laravel-amqp/issues/112). See also the [Quorum Queues documentation](../modules/QUORUM_QUEUES.md) for cluster-aware queue types.

### Q: How do I use multiple RabbitMQ instances (like DB::connection('foo'))?

**A:** The package now supports multiple RabbitMQ instances through the `use` parameter and `getConnectionConfig()` helper method. You can configure multiple RabbitMQ instances and use them easily.

**Step 1: Configure Multiple Instances**

In your `config/amqp.php`, define multiple connection configurations:

```php
'properties' => [
    'production' => [
        'host' => env('AMQP_HOST', 'localhost'),
        'port' => env('AMQP_PORT', 5672),
        'username' => env('AMQP_USER', 'guest'),
        'password' => env('AMQP_PASSWORD', 'guest'),
        'vhost' => env('AMQP_VHOST', '/'),
        'exchange' => 'events',
        'exchange_type' => 'topic',
    ],
    'analytics' => [
        'host' => env('AMQP_ANALYTICS_HOST', 'analytics-rabbitmq.example.com'),
        'port' => env('AMQP_ANALYTICS_PORT', 5672),
        'username' => env('AMQP_ANALYTICS_USER', 'analytics'),
        'password' => env('AMQP_ANALYTICS_PASSWORD', 'secret'),
        'vhost' => '/analytics',
        'exchange' => 'analytics',
        'exchange_type' => 'topic',
    ],
    'notifications' => [
        'host' => env('AMQP_NOTIFICATIONS_HOST', 'notifications-rabbitmq.example.com'),
        'port' => env('AMQP_NOTIFICATIONS_PORT', 5672),
        'username' => env('AMQP_NOTIFICATIONS_USER', 'notifications'),
        'password' => env('AMQP_NOTIFICATIONS_PASSWORD', 'secret'),
        'vhost' => '/notifications',
        'exchange' => 'notifications',
        'exchange_type' => 'direct',
    ],
],
```

**Step 2: Use Different Instances**

You can use different instances in two ways:

**Option 1: Using the `use` Parameter (Recommended)**

```php
use Bschmitt\Amqp\Facades\Amqp;

// Use default/production instance
Amqp::publish('user.created', json_encode($data));

// Use analytics instance
Amqp::publish('event.tracked', json_encode($analyticsData), [
    'use' => 'analytics',  // Automatically loads analytics config
]);

// Use notifications instance
Amqp::publish('email.send', json_encode($emailData), [
    'use' => 'notifications',
]);
```

**Consuming from Different Instances:**

```php
// Consume from production instance (default)
Amqp::consume('user-events-queue', function ($message, $resolver) {
    $resolver->acknowledge($message);
});

// Consume from analytics instance
Amqp::consume('analytics-queue', function ($message, $resolver) {
    $resolver->acknowledge($message);
}, [
    'use' => 'analytics',
]);

// Consume from notifications instance
Amqp::consume('notifications-queue', function ($message, $resolver) {
    $resolver->acknowledge($message);
}, [
    'use' => 'notifications',
]);
```

**Option 2: Using `getConnectionConfig()` Helper**

```php
use Bschmitt\Amqp\Facades\Amqp;

// Get connection config
$analyticsConfig = Amqp::getConnectionConfig('analytics');

// Use it with publish/consume
Amqp::publish('event.tracked', json_encode($data), $analyticsConfig);
Amqp::consume('analytics-queue', $callback, $analyticsConfig);
```

**Alternative: Helper Method**

You can create a helper method to make it more convenient:

```php
// In a service class or helper
class AmqpConnection
{
    public static function publishTo($connection, $routing, $message, $properties = [])
    {
        $config = config("amqp.properties.{$connection}", []);
        $merged = array_merge($config, $properties);
        
        return Amqp::publish($routing, $message, $merged);
    }
    
    public static function consumeFrom($connection, $queue, $callback, $properties = [])
    {
        $config = config("amqp.properties.{$connection}", []);
        $merged = array_merge($config, $properties);
        
        return Amqp::consume($queue, $callback, $merged);
    }
}

// Usage
AmqpConnection::publishTo('analytics', 'event.tracked', $data);
AmqpConnection::consumeFrom('notifications', 'notifications-queue', $callback);
```

**Using with 'use' Parameter**

You can also use the `use` parameter if your config is set up correctly:

```php
// In config/amqp.php
'properties' => [
    'analytics' => [
        'host' => 'analytics-rabbitmq.example.com',
        // ... other settings
    ],
],

// In your code
Amqp::publish('event.tracked', $data, [
    'use' => 'analytics',  // Uses the 'analytics' config
]);
```

**Important Notes:**

1. **`use` Parameter**: The `use` parameter automatically loads and merges the connection configuration
2. **One Connection per Call**: The package manages one connection per call, but you can switch between instances using the `use` parameter
3. **Config Merging**: Properties passed to `publish()` or `consume()` are merged with the connection config, allowing you to override specific settings
4. **Helper Method**: Use `getConnectionConfig('connection-name')` to retrieve connection configuration programmatically

**Best Practices:**

1. **Use Environment Variables**: Store connection details in `.env`:
   ```env
   AMQP_HOST=localhost
   AMQP_ANALYTICS_HOST=analytics-rabbitmq.example.com
   AMQP_NOTIFICATIONS_HOST=notifications-rabbitmq.example.com
   ```

2. **Separate by Purpose**: Create separate instances for different purposes (analytics, notifications, events, etc.)

3. **Use Helper Methods**: Create wrapper methods for cleaner code

4. **Document Your Connections**: Keep track of which instance is used for what purpose

**Complete Example:**

```php
// In config/amqp.php
'properties' => [
    'default' => [
        'host' => env('AMQP_HOST', 'localhost'),
        'port' => 5672,
        'username' => 'guest',
        'password' => 'guest',
        'exchange' => 'events',
        'exchange_type' => 'topic',
    ],
    'analytics' => [
        'host' => env('AMQP_ANALYTICS_HOST', 'analytics.example.com'),
        'port' => 5672,
        'username' => env('AMQP_ANALYTICS_USER'),
        'password' => env('AMQP_ANALYTICS_PASSWORD'),
        'exchange' => 'analytics',
        'exchange_type' => 'topic',
    ],
],

// In your controller/service
class EventService
{
    public function publishUserEvent($data)
    {
        // Use default instance
        Amqp::publish('user.created', json_encode($data));
    }
    
    public function publishAnalytics($data)
    {
        // Use analytics instance
        Amqp::publish('event.tracked', json_encode($data), 
            config('amqp.properties.analytics')
        );
    }
}
```

**Reference:** This question was asked in [GitHub Issue #68](https://github.com/bschmitt/laravel-amqp/issues/68). The package currently supports multiple connection configurations, but you need to pass the configuration properties directly to `publish()` or `consume()` methods rather than using a `connection()` method like Laravel's database connections.

### Q: How do I connect to a partner's message broker without using an exchange?

**A:** This is a common scenario when integrating with third-party RabbitMQ servers that have specific requirements. Here are your options:

**Understanding the Default Exchange:**

In RabbitMQ, there's a special "default exchange" (empty string `""`) that allows you to publish directly to queues by name. However, the current package version requires an exchange to be defined.

**Option 1: Use the Default Exchange Name (If Supported)**

Some brokers accept the default exchange name. Try using an empty string or the default exchange name:

```php
// In config/amqp.php
'properties' => [
    'production' => [
        'exchange' => '',  // Default exchange (empty string)
        'exchange_type' => 'direct',  // Default exchange is direct
        // ... other settings
    ],
],
```

**Or override per call in your controller:**

```php
// In your controller - override exchange settings per call
Amqp::publish('routing.key', 'message', [
    'exchange' => '',  // Default exchange (empty string)
    'exchange_type' => 'direct',
]);
```

**Note:** The current package version validates that exchange is not empty, so this may not work without code modification.

**Option 2: Use a Minimal Exchange Name**

If your partner's broker requires an exchange name but you want minimal configuration, try using RabbitMQ's built-in exchanges:

**In config file:**

```php
// Use the default direct exchange (amq.direct)
'properties' => [
    'production' => [
        'exchange' => 'amq.direct',  // Built-in direct exchange
        'exchange_type' => 'direct',
        // Publish directly to queue by using queue name as routing key
        'routing' => ['your-queue-name'],
    ],
],
```

**Or override per call in your controller:**

```php
// In your controller - specify exchange per call
Amqp::publish('your-queue-name', 'message', [
    'exchange' => 'amq.direct',  // Built-in direct exchange
    'exchange_type' => 'direct',
    'routing' => ['your-queue-name'],  // Queue name as routing key
]);
```

**Option 3: Publish Directly to Queue (Workaround)**

If you need to publish directly to a queue without an exchange, you can work around the limitation by:

1. **Using the queue name as both exchange and routing key:**

```php
// Publish directly to a queue
Amqp::publish('your-queue-name', 'message', [
    'exchange' => 'amq.direct',  // Use default direct exchange
    'exchange_type' => 'direct',
    'queue' => 'your-queue-name',
    'routing' => ['your-queue-name'],  // Queue name as routing key
]);
```

2. **For consuming, bind to the default exchange:**

```php
// Consume from queue without custom exchange
Amqp::consume('your-queue-name', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'exchange' => 'amq.direct',
    'exchange_type' => 'direct',
    'queue' => 'your-queue-name',
    'routing' => ['your-queue-name'],
]);
```

**Option 4: Modify Package Code (Advanced)**

If you need to use the default exchange (empty string), you can modify the validation in the package:

**File:** `/laravel-amqp/src/Core/Request.php`

```php
// Around line 79, modify the validation:
$exchange = $this->getProperty('exchange');

// Allow empty string for default exchange
if ($exchange === null || ($exchange !== '' && empty($exchange))) {
    throw new \Bschmitt\Amqp\Exception\Configuration('Please check your settings, exchange is not defined.');
}

// Skip exchange declaration if using default exchange
if ($exchange !== '') {
    // ... existing exchange declaration code ...
}
```

**File:** `/laravel-amqp/src/Managers/ExchangeManager.php`

```php
// Around line 43, modify the validation:
$exchange = $this->config->getProperty('exchange');

// Allow empty string for default exchange
if ($exchange === null || ($exchange !== '' && empty($exchange))) {
    throw new \Bschmitt\Amqp\Exception\Configuration('Exchange is not defined in configuration.');
}

// Skip declaration if using default exchange
if ($exchange === '') {
    return;  // Default exchange doesn't need declaration
}
```

**Important Notes:**

1. **Default Exchange Behavior:**
   - The default exchange (`""`) is a direct exchange
   - It routes messages to queues where the routing key matches the queue name exactly
   - No exchange declaration is needed for the default exchange

2. **Partner Broker Requirements:**
   - Some brokers don't allow custom exchange creation
   - Some brokers only accept specific exchange names
   - Contact your partner to understand their exact requirements

3. **Best Practice:**
   - If possible, coordinate with your partner to use a standard exchange name
   - Document the exchange requirements in your integration documentation
   - Test thoroughly in a staging environment before production

**Example: Publishing to Partner's Queue**

You can define exchange settings either in your config file or directly in your controller when calling `publish()` or `consume()`. The properties passed to these methods will override your config:

```php
// In your controller - override exchange settings per call
// Assuming partner's queue is named "partner-queue"
Amqp::publish('partner-queue', json_encode([
    'data' => 'your message data',
]), [
    'exchange' => 'amq.direct',  // Use default direct exchange
    'exchange_type' => 'direct',
    'routing' => ['partner-queue'],  // Queue name as routing key
]);

// Or for consuming:
Amqp::consume('partner-queue', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'exchange' => 'amq.direct',  // Override exchange per call
    'exchange_type' => 'direct',
    'routing' => ['partner-queue'],
]);
```

**Key Point:** You can specify `exchange` and `exchange_type` directly in your controller methods (`publish()` and `consume()`), and these will override any settings in your config file. This is useful when working with partner brokers that have different requirements than your default configuration.

**Reference:** This question was asked in [GitHub Issue #78](https://github.com/bschmitt/laravel-amqp/issues/78). The package currently requires an exchange to be defined, but workarounds exist for connecting to partner brokers with specific requirements.

### Q: How do I send direct messages to a queue when my default exchange_type is 'topic'?

**A:** This is a common scenario! When you have `exchange_type = topic` in your config, all publish calls use the topic exchange by default. To send direct messages to a specific queue (without broadcasting to all queues), you need to override the exchange type per call.

**The Problem:**

When you have a topic exchange as default and try to publish directly to a queue:

```php
// In config/amqp.php
'exchange_type' => 'topic',  // Default is topic

// In your code
Amqp::publish('', json_encode($data), [
    'queue' => 'my-queue'
]);
```

This will bind your queue to the topic exchange, and if you have multiple queues, they might all receive the message (depending on routing keys).

**Solution: Override Exchange Type Per Call**

You can override `exchange_type` directly in your `publish()` call to use a direct exchange or the default exchange:

**Option 1: Use Direct Exchange for Direct Queue Messages**

```php
// For topic exchange (broadcast to multiple queues)
Amqp::publish('user.created', json_encode($data), [
    'exchange_type' => 'topic',  // Use topic exchange
    'routing' => ['user.created'],
]);

// For direct queue messages (send to one specific queue only)
Amqp::publish('my-queue', json_encode($data), [
    'exchange' => 'amq.direct',  // Use direct exchange
    'exchange_type' => 'direct',  // Override to direct
    'queue' => 'my-queue',
    'routing' => ['my-queue'],  // Queue name as routing key
]);
```

**Option 2: Use Default Exchange (Empty String)**

The default exchange routes messages directly to queues by name:

```php
// For topic exchange (broadcast)
Amqp::publish('user.created', json_encode($data), [
    'exchange_type' => 'topic',
    'routing' => ['user.created'],
]);

// For direct queue messages (using default exchange)
Amqp::publish('my-queue', json_encode($data), [
    'exchange' => '',  // Default exchange (empty string)
    'exchange_type' => 'direct',  // Default exchange is direct
    'queue' => 'my-queue',
    'routing' => ['my-queue'],  // Queue name must match routing key
]);
```

**Note:** The default exchange (empty string) may require code modification as mentioned in the previous FAQ entry.

**Option 3: Use Separate Exchanges**

Create separate exchanges for different purposes:

```php
// In config/amqp.php
'properties' => [
    'production' => [
        'exchange' => 'events',  // Default exchange for topics
        'exchange_type' => 'topic',
    ],
    'direct-queue' => [
        'exchange' => 'amq.direct',  // Direct exchange for queues
        'exchange_type' => 'direct',
    ],
],

// In your code
// For topic exchange
Amqp::publish('user.created', json_encode($data), [
    'use' => 'production',  // Use topic exchange config
    'routing' => ['user.created'],
]);

// For direct queue messages
Amqp::publish('my-queue', json_encode($data), [
    'use' => 'direct-queue',  // Use direct exchange config
    'queue' => 'my-queue',
    'routing' => ['my-queue'],
]);
```

**Complete Example: Mixed Usage**

Here's a complete example showing both patterns:

```php
// Service A: Publish to topic (broadcast to multiple consumers)
Amqp::publish('user.created', json_encode([
    'user_id' => 123,
    'email' => 'user@example.com',
]), [
    'exchange' => 'events',
    'exchange_type' => 'topic',
    'routing' => ['user.created'],
]);

// Service B: Publish directly to a specific queue (one consumer only)
Amqp::publish('api-queue-1', json_encode([
    'action' => 'process',
    'data' => $data,
]), [
    'exchange' => 'amq.direct',  // Direct exchange
    'exchange_type' => 'direct',  // Override topic to direct
    'queue' => 'api-queue-1',
    'routing' => ['api-queue-1'],  // Queue name as routing key
]);

// Service C: Another direct queue message
Amqp::publish('api-queue-2', json_encode([
    'action' => 'process',
    'data' => $data,
]), [
    'exchange' => 'amq.direct',
    'exchange_type' => 'direct',
    'queue' => 'api-queue-2',
    'routing' => ['api-queue-2'],
]);
```

**Consuming Direct Queue Messages**

When consuming from queues that receive direct messages:

```php
// Consume from a queue that receives direct messages
Amqp::consume('api-queue-1', function ($message, $resolver) {
    $data = json_decode($message->body, true);
    // Process message
    $resolver->acknowledge($message);
}, [
    'exchange' => 'amq.direct',  // Direct exchange
    'exchange_type' => 'direct',  // Override topic to direct
    'queue' => 'api-queue-1',
    'routing' => ['api-queue-1'],
]);
```

**Key Points:**

1. **Override per call**: You can override `exchange_type` in each `publish()` or `consume()` call
2. **Direct exchange**: Use `exchange_type => 'direct'` for direct queue messages
3. **Queue name as routing key**: When using direct exchange, the routing key should match the queue name
4. **Separate exchanges**: Consider using different exchanges for different purposes
5. **No binding issues**: Direct exchange won't bind queues to topic exchange

**Why This Happens:**

- Topic exchanges route messages based on routing key patterns
- When you use an empty routing key (`''`) with a topic exchange, it can match multiple bindings
- Direct exchanges route messages to queues where the routing key exactly matches the queue name
- By overriding to `direct`, you ensure messages go to only one specific queue

**Reference:** This question was asked in [GitHub Issue #71](https://github.com/bschmitt/laravel-amqp/issues/71). The solution is to override the `exchange_type` per call when you need to send direct messages to specific queues while keeping topic exchange as your default.

### Q: How do I use RabbitMQ sharding (x-modulus-hash exchange type)?

**A:** RabbitMQ sharding is a plugin feature that allows you to distribute messages across multiple queue shards. However, the current package version has a limitation: it only validates standard exchange types (`topic`, `direct`, `fanout`, `headers`) and doesn't support plugin exchange types like `x-modulus-hash` by default.

**The Problem:**

When you try to use `x-modulus-hash` exchange type:

```php
Amqp::publish('fc_analyze', 'message', [
    'exchange' => 'shard.videos',
    'exchange_type' => 'x-modulus-hash',  // ❌ This will fail validation
]);
```

You'll get an error: `Invalid exchange type 'x-modulus-hash'`

**Solution: Modify Package Code (Required)**

To use RabbitMQ sharding, you need to modify the exchange type validation in the package:

**File:** `packages/zfhassaan/laravel-amqp/src/Core/Request.php`

Find the `validateExchangeType` method (around line 205) and update it:

```php
protected function validateExchangeType($exchangeType): void
{
    $validTypes = ['topic', 'direct', 'fanout', 'headers', 'x-modulus-hash'];
    
    if (empty($exchangeType) || !in_array($exchangeType, $validTypes, true)) {
        $validTypesList = implode(', ', $validTypes);
        throw new \Bschmitt\Amqp\Exception\Configuration(
            "Invalid exchange type '{$exchangeType}'. Valid types are: {$validTypesList}"
        );
    }
}
```

**Or, for more flexibility, allow any exchange type starting with 'x-' (plugin exchange types):**

```php
protected function validateExchangeType($exchangeType): void
{
    $validTypes = ['topic', 'direct', 'fanout', 'headers'];
    
    // Allow plugin exchange types (starting with 'x-')
    $isPluginType = !empty($exchangeType) && strpos($exchangeType, 'x-') === 0;
    
    if (empty($exchangeType) || (!in_array($exchangeType, $validTypes, true) && !$isPluginType)) {
        $validTypesList = implode(', ', $validTypes);
        throw new \Bschmitt\Amqp\Exception\Configuration(
            "Invalid exchange type '{$exchangeType}'. Valid types are: {$validTypesList} or plugin types (x-*)"
        );
    }
}
```

**Complete Sharding Setup Example:**

After modifying the code, here's how to set up and use sharding:

**1. Enable RabbitMQ Sharding Plugin:**

```bash
rabbitmq-plugins enable rabbitmq_sharding
```

**2. Configure in Laravel:**

```php
// In config/amqp.php
'properties' => [
    'production' => [
        'exchange' => 'shard.videos',
        'exchange_type' => 'x-modulus-hash',
        // ... other settings
    ],
],
```

**3. Set Up Sharding Policy (via RabbitMQ CLI):**

```bash
rabbitmqctl set_policy videos-shard "^shard.videos$" \
  '{"shards-per-node": 1, "routing-key": "fc_analyze"}' \
  --apply-to exchanges
```

**4. Publish Messages:**

```php
Amqp::publish('fc_analyze', json_encode($data), [
    'exchange' => 'shard.videos',
    'exchange_type' => 'x-modulus-hash',
]);
```

**5. Consume from Sharded Queues:**

**Important:** When consuming from sharded exchanges, you need to consume from the actual shard queue names, not the exchange name. RabbitMQ creates queues like `shard.videos-0`, `shard.videos-1`, etc.

```php
// Consume from a specific shard (e.g., shard.videos-0)
Amqp::consume('shard.videos-0', function ($message, $resolver) {
    var_dump($message->body);
    $resolver->acknowledge($message);
}, [
    'exchange' => 'shard.videos',
    'exchange_type' => 'x-modulus-hash',
    'routing' => ['fc_analyze'],  // Use routing array, not routing_key
]);

// Or consume from all shards (run multiple consumers)
for ($i = 0; $i < 6; $i++) {
    $queueName = "shard.videos-{$i}";
    
    // Run each consumer in a separate process/worker
    Amqp::consume($queueName, function ($message, $resolver) {
        // Process message
        $resolver->acknowledge($message);
    }, [
        'exchange' => 'shard.videos',
        'exchange_type' => 'x-modulus-hash',
        'routing' => ['fc_analyze'],
    ]);
}
```

**Important Notes:**

1. **Queue Names with Dots**: The package should accept queue names with dots (like `shard.videos-0`). If you encounter issues, ensure you're using the correct queue name format.

2. **Shard Queue Names**: RabbitMQ creates shard queues automatically with names like `{exchange-name}-{shard-number}`. You need to consume from these specific queue names.

3. **Multiple Consumers**: To achieve your goal of 6 consumers (one per CPU), you need to run 6 separate consumer processes, each consuming from a different shard queue (`shard.videos-0` through `shard.videos-5`).

4. **Routing Key**: Make sure the routing key in your policy matches the routing key you use when publishing (`fc_analyze` in your example).

5. **Use 'routing' Array**: When consuming, use the `routing` array parameter, not `routing_key`:

```php
//  Correct
'routing' => ['fc_analyze']

// ❌ Wrong
'routing_key' => 'fc_analyze'
```

**Alternative: Use Artisan Commands for Multiple Shard Consumers**

Create separate Artisan commands for each shard:

```php
// app/Console/Commands/ConsumeShard.php
class ConsumeShard extends Command
{
    protected $signature = 'amqp:consume-shard {shard}';
    
    public function handle()
    {
        $shardNumber = $this->argument('shard');
        $queueName = "shard.videos-{$shardNumber}";
        
        Amqp::consume($queueName, function ($message, $resolver) {
            // Process message
            $resolver->acknowledge($message);
        }, [
            'exchange' => 'shard.videos',
            'exchange_type' => 'x-modulus-hash',
            'routing' => ['fc_analyze'],
        ]);
    }
}
```

Then run 6 separate processes:

```bash
php artisan amqp:consume-shard 0 &
php artisan amqp:consume-shard 1 &
php artisan amqp:consume-shard 2 &
php artisan amqp:consume-shard 3 &
php artisan amqp:consume-shard 4 &
php artisan amqp:consume-shard 5 &
```

**Reference:** This question was asked in [GitHub Issue #67](https://github.com/bschmitt/laravel-amqp/issues/67). The package currently doesn't support plugin exchange types like `x-modulus-hash` out of the box, but you can modify the validation to enable this feature. Sharding requires consuming from the actual shard queue names created by RabbitMQ.

### Q: How do I use an existing exchange with only write permissions?

**A:** By default, the package tries to declare (create) exchanges, which requires `configure` permissions. If you only have `write` permissions on an existing exchange, you can use the `exchange_passive` parameter to check if the exchange exists without trying to create it.

**The Problem:**

When you try to publish to an existing exchange with only write permissions:

```php
Amqp::publish('routing.key', 'message', [
    'exchange' => 'existing-exchange',
]);
```

You'll get an error: `ACCESS_REFUSED - operation not permitted` because the package tries to declare the exchange, which requires configure permissions.

**Solution: Use Passive Exchange Declaration**

Set `exchange_passive` to `true` to check if the exchange exists without trying to create it:

**In Config File:**

```php
// In config/amqp.php
'properties' => [
    'production' => [
        'exchange' => 'existing-exchange',
        'exchange_type' => 'topic',
        'exchange_passive' => true,  //  Check if exists, don't create
        // ... other settings
    ],
],
```

**Or Override Per Call:**

```php
// In your controller/service
Amqp::publish('routing.key', 'message', [
    'exchange' => 'existing-exchange',
    'exchange_type' => 'topic',
    'exchange_passive' => true,  //  Use passive mode
]);
```

**How Passive Mode Works:**

- **`exchange_passive => false`** (default): Tries to declare/create the exchange (requires `configure` permission)
- **`exchange_passive => true`**: Only checks if the exchange exists (requires `read` permission, which is typically included with `write`)

**Complete Example:**

```php
// User has only write permissions on 'events' exchange
Amqp::publish('user.created', json_encode($data), [
    'exchange' => 'events',
    'exchange_type' => 'topic',
    'exchange_passive' => true,  // Don't try to create, just verify it exists
    'routing' => ['user.created'],
]);
```

**For Consuming:**

You can also use passive mode when consuming:

```php
Amqp::consume('queue-name', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'exchange' => 'existing-exchange',
    'exchange_type' => 'topic',
    'exchange_passive' => true,  // Use passive mode
    'routing' => ['user.created'],
]);
```

**RabbitMQ Permissions Explained:**

RabbitMQ has three types of permissions:
- **configure**: Create/delete exchanges and queues
- **write**: Publish messages to exchanges
- **read**: Consume messages from queues

When you have only `write` permissions:
-  You can publish to existing exchanges
-  You can use passive exchange declaration (checks if exists)
- ❌ You cannot create/declare new exchanges
- ❌ You cannot delete exchanges

**Best Practices:**

1. **Use passive mode for existing exchanges**: If the exchange is managed by another service or administrator, use `exchange_passive => true`

2. **Separate configurations**: Create different config profiles for different permission levels:

```php
'properties' => [
    'publisher' => [
        'exchange' => 'events',
        'exchange_passive' => true,  // Only write permissions
    ],
    'admin' => [
        'exchange' => 'events',
        'exchange_passive' => false,  // Full configure permissions
    ],
],
```

3. **Error handling**: Handle cases where the exchange doesn't exist:

```php
try {
    Amqp::publish('routing.key', 'message', [
        'exchange' => 'existing-exchange',
        'exchange_passive' => true,
    ]);
} catch (\PhpAmqpLib\Exception\AMQPProtocolChannelException $e) {
    if (strpos($e->getMessage(), 'NOT_FOUND') !== false) {
        // Exchange doesn't exist - handle accordingly
        \Log::error('Exchange does not exist');
    }
    throw $e;
}
```

**Note:** The same principle applies to queues. Use `queue_passive => true` if you only have read permissions on an existing queue and don't want to create it.

**Reference:** This question was asked in [GitHub Issue #37](https://github.com/bschmitt/laravel-amqp/issues/37). The package supports passive exchange declaration via the `exchange_passive` parameter, which allows you to use existing exchanges with only write permissions.

### Q: How do I disable exchange and queue declaration to avoid property conflicts?

**A:** If you have queues or exchanges that were already declared with specific properties (like dead letter exchange), the package will try to redeclare them with potentially different properties, causing RabbitMQ errors. You can use passive mode to skip declaration and just verify they exist.

**The Problem:**

When a queue is already declared with specific properties (e.g., dead letter exchange), and your code tries to declare it again with different or missing properties:

```php
// Queue was already created with dead letter exchange
// Your code tries to declare it without that property
Amqp::consume('my-queue', $callback, [
    // Missing 'x-dead-letter-exchange' property
]);
```

RabbitMQ will throw an error: `PRECONDITION_FAILED - inequivalent arg 'x-dead-letter-exchange'`

**Solution 1: Use Passive Mode (Recommended)**

Use `exchange_passive` and `queue_passive` to check if they exist without trying to declare them:

```php
// For existing exchanges
Amqp::publish('routing.key', 'message', [
    'exchange' => 'existing-exchange',
    'exchange_passive' => true,  //  Don't declare, just verify exists
]);

// For existing queues
Amqp::consume('existing-queue', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'queue' => 'existing-queue',
    'queue_passive' => true,  //  Don't declare, just verify exists
    'exchange' => 'existing-exchange',
    'exchange_passive' => true,
]);
```

**Solution 2: Omit Queue Name (Skip Queue Declaration)**

If you don't specify a queue name, the package won't try to declare a queue:

```php
// Publish without queue declaration
Amqp::publish('routing.key', 'message', [
    'exchange' => 'existing-exchange',
    'exchange_passive' => true,
    // No 'queue' parameter = no queue declaration
]);
```

**Solution 3: Include All Queue Properties**

If you must declare the queue, ensure all properties match the existing queue:

```php
// Include ALL properties that the existing queue has
Amqp::consume('my-queue', $callback, [
    'queue' => 'my-queue',
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx-exchange',  // Must match existing
        'x-dead-letter-routing-key' => 'dlx.key',   // Must match existing
        'x-max-priority' => 10,                      // Must match existing
        // ... all other properties that exist on the queue
    ],
]);
```

**Solution 4: Use queue_force_declare (If You Need to Override)**

If you need to force declaration (and have permissions), you can use `queue_force_declare`:

```php
Amqp::consume('my-queue', $callback, [
    'queue' => 'my-queue',
    'queue_force_declare' => true,  // Force declaration even if queue exists
    'queue_properties' => [
        'x-dead-letter-exchange' => 'new-dlx-exchange',  // New properties
    ],
]);
```

**Note:** This will fail if the queue already exists with different properties unless you have permissions to delete it first.

**About Queue Properties Merging:**

The package doesn't automatically merge `queue_properties` with defaults. You need to specify all properties explicitly:

```php
// ❌ This won't work if queue has other properties
Amqp::consume('my-queue', $callback, [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx',
    ],
    // Missing other properties that exist on the queue
]);

//  Include all properties
Amqp::consume('my-queue', $callback, [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx',
        'x-max-priority' => 10,
        'x-queue-type' => 'classic',
        // ... all properties
    ],
]);
```

**Best Practice: Use Passive Mode for Existing Resources**

For queues and exchanges that are managed elsewhere (by another service, administrator, or infrastructure as code):

```php
// In config/amqp.php
'properties' => [
    'production' => [
        'exchange' => 'events',
        'exchange_passive' => true,  // Don't declare exchanges
        'queue_passive' => true,      // Don't declare queues
        // ... other settings
    ],
],
```

**Complete Example: Using Existing Queue with Dead Letter Exchange**

```php
// Queue was created externally with:
// - x-dead-letter-exchange: 'dlx'
// - x-dead-letter-routing-key: 'dlx.key'
// - x-max-priority: 10

//  Correct: Use passive mode
Amqp::consume('my-queue', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'queue' => 'my-queue',
    'queue_passive' => true,  // Don't try to declare
    'exchange' => 'events',
    'exchange_passive' => true,
    'routing' => ['user.created'],
]);

// ❌ Wrong: Tries to declare without matching properties
Amqp::consume('my-queue', function ($message, $resolver) {
    // This will fail with PRECONDITION_FAILED
}, [
    'queue' => 'my-queue',
    // Missing queue_passive and queue_properties
]);
```

**Performance Consideration:**

Using passive mode also reduces unnecessary server communication:
- **Active declaration**: Sends declare command every time (even if resource exists)
- **Passive mode**: Only checks if resource exists (faster, less network traffic)

**Reference:** This question was asked in [GitHub Issue #36](https://github.com/bschmitt/laravel-amqp/issues/36). The package supports passive mode via `exchange_passive` and `queue_passive` parameters to avoid property conflicts and reduce unnecessary server communication when working with existing queues and exchanges.

### Q: Can I use SSL/TLS connections?

**A:** Absolutely! Just configure the `ssl_options` in your config:

```php
'ssl_options' => [
    'cafile' => '/path/to/ca.pem',
    'local_cert' => '/path/to/cert.pem',
    'local_pk' => '/path/to/key.pem',
    'verify_peer' => true,
],
```

### Q: I'm getting "AMQPInvalidFrameException: Invalid frame type 21" error. What does this mean?

**A:** This error indicates a protocol-level issue between your PHP client and RabbitMQ server. The "Invalid frame type 21" means the AMQP protocol received an unexpected frame type, which usually indicates one of these problems:

**Common causes and solutions:**

1. **Connection timeout or network issues**
   - Increase connection and read/write timeouts:
   ```php
   'connect_options' => [
       'connection_timeout' => 10.0,      // Increase from default 3.0
       'read_write_timeout' => 130,      // Keep this reasonable
       'heartbeat' => 60,                 // Ensure heartbeat is set
   ],
   ```

2. **Connection reuse problems**
   - Make sure connections are properly closed after use
   - The package handles this automatically, but if you're using connections directly, ensure cleanup:
   ```php
   try {
       Amqp::publish('key', 'message');
   } finally {
       // Connection is automatically closed
   }
   ```

3. **SSL/TLS configuration issues**
   - If using SSL, verify your SSL options are correct:
   ```php
   'ssl_options' => [
       'verify_peer' => false,  // Try false for testing
       'verify_peer_name' => false,
   ],
   ```

4. **RabbitMQ server issues**
   - Check RabbitMQ logs: `docker logs rabbitmq` or check server logs
   - Restart RabbitMQ if needed
   - Verify RabbitMQ version compatibility (package tested with RabbitMQ 3.x)

5. **Protocol version mismatch**
   - Ensure your php-amqplib version is compatible with RabbitMQ
   - Update php-amqplib: `composer update php-amqplib/php-amqplib`
   - Check RabbitMQ version matches your test environment (`rabbitmq:3-management`)

6. **Heartbeat/timeout configuration**
   - Ensure heartbeat is properly configured:
   ```php
   'connect_options' => [
       'heartbeat' => 60,  // 60 seconds heartbeat
   ],
   ```

**Debugging steps:**

1. **Check RabbitMQ connection status:**
   ```bash
   # Using Management UI
   http://localhost:15672 -> Connections
   
   # Or using Management API
   Amqp::getConnections();
   ```

2. **Enable error logging:**
   ```php
   try {
       Amqp::publish('key', 'message');
   } catch (\PhpAmqpLib\Exception\AMQPInvalidFrameException $e) {
       \Log::error('AMQP Frame Error: ' . $e->getMessage());
       // Handle or rethrow
   }
   ```

3. **Test with a fresh connection:**
   - Restart your application
   - Restart RabbitMQ server
   - Check for any lingering connections in RabbitMQ Management UI

4. **Verify network stability:**
   - Check for network interruptions
   - Verify firewall rules
   - Test with `telnet localhost 5672` to ensure port is accessible

**If the error persists:**
- Check the full stack trace for more context
- Review RabbitMQ server logs for corresponding errors
- Try connecting with a different RabbitMQ client to isolate the issue
- Consider using a connection pool or reconnection strategy

**Reference:** This error was reported in [GitHub Issue #116](https://github.com/bschmitt/laravel-amqp/issues/116).

---

## Publishing Messages

### Q: My messages aren't being delivered. What could be wrong?

**A:** A few things to check:

1. **Exchange exists?** Make sure the exchange is declared
2. **Routing key matches?** Check if your routing key matches any queue bindings
3. **Queue exists?** If using a specific queue, ensure it's created
4. **Enable publisher confirms** to get delivery feedback:

```php
Amqp::publish('routing.key', 'message', [
    'publisher_confirms' => true,
    'wait_for_confirms' => true,
]);
```

### Q: How do I use wildcard routing in topic exchanges?

**A:** Yes, wildcard routing is fully supported! This is a native RabbitMQ feature for topic exchanges. You can use `*` (star) and `#` (hash) wildcards in your binding keys.

**How Wildcards Work:**

- `*` (star) matches exactly **one word** in the routing key
- `#` (hash) matches **zero or more words** in the routing key
- Words are separated by dots (`.`)

**Example: Logging System**

Let's say you have a logging system with routing keys like `facility.severity` (e.g., `kern.critical`, `auth.info`, `cron.warning`):

**Publishing messages:**

```php
// Publish different types of logs
Amqp::publish('kern.critical', 'Critical kernel error', [
    'exchange' => 'logs',
    'exchange_type' => 'topic',
]);

Amqp::publish('auth.info', 'User logged in', [
    'exchange' => 'logs',
    'exchange_type' => 'topic',
]);

Amqp::publish('cron.warning', 'Scheduled task delayed', [
    'exchange' => 'logs',
    'exchange_type' => 'topic',
]);
```

**Consuming with wildcards:**

```php
// Consumer 1: Listen to all critical logs from any facility
Amqp::consume('critical-logs-queue', function ($message, $resolver) {
    echo "Critical: " . $message->body . "\n";
    $resolver->acknowledge($message);
}, [
    'exchange' => 'logs',
    'exchange_type' => 'topic',
    'routing' => ['*.critical'],  // Matches: kern.critical, auth.critical, etc.
]);

// Consumer 2: Listen to all logs from 'kern' facility
Amqp::consume('kern-logs-queue', function ($message, $resolver) {
    echo "Kern: " . $message->body . "\n";
    $resolver->acknowledge($message);
}, [
    'exchange' => 'logs',
    'exchange_type' => 'topic',
    'routing' => ['kern.*'],  // Matches: kern.critical, kern.info, kern.warning, etc.
]);

// Consumer 3: Listen to ALL logs (everything)
Amqp::consume('all-logs-queue', function ($message, $resolver) {
    echo "All: " . $message->body . "\n";
    $resolver->acknowledge($message);
}, [
    'exchange' => 'logs',
    'exchange_type' => 'topic',
    'routing' => ['#'],  // Matches everything: kern.critical, auth.info, cron.warning, etc.
]);

// Consumer 4: Multiple bindings - listen to both kern.* and *.critical
Amqp::consume('combined-logs-queue', function ($message, $resolver) {
    echo "Combined: " . $message->body . "\n";
    $resolver->acknowledge($message);
}, [
    'exchange' => 'logs',
    'exchange_type' => 'topic',
    'routing' => ['kern.*', '*.critical'],  // Matches either pattern
]);
```

**More Complex Examples:**

For routing keys with more words like `speed.color.species` (e.g., `quick.orange.rabbit`, `lazy.brown.fox`):

```php
// Match all orange animals (any speed, orange, any species)
'routing' => ['*.orange.*']

// Match all rabbits (any speed, any color, rabbit)
'routing' => ['*.*.rabbit']

// Match all lazy animals (lazy, any color, any species)
'routing' => ['lazy.#']

// Match everything starting with 'quick'
'routing' => ['quick.#']
```

**Important Notes:**

1. **Wildcards only work in binding keys** (when consuming), not in routing keys (when publishing)
   -  Correct: `'routing' => ['*.critical']` (binding key with wildcard)
   - ❌ Wrong: `Amqp::publish('*.critical', ...)` (routing key cannot have wildcards)

2. **Topic exchange required**: Wildcards only work with `exchange_type => 'topic'`

3. **Multiple bindings**: You can bind a queue with multiple patterns:
   ```php
   'routing' => ['kern.*', '*.critical', 'auth.#']
   ```

4. **Routing key format**: Routing keys must be dot-separated words (e.g., `kern.critical`, not `kern-critical`)

**Real-World Use Case:**

```php
// In your config/amqp.php
'properties' => [
    'production' => [
        'exchange' => 'events',
        'exchange_type' => 'topic',
    ],
],

// Publish events
Amqp::publish('user.created', json_encode($userData));
Amqp::publish('user.updated', json_encode($userData));
Amqp::publish('order.placed', json_encode($orderData));
Amqp::publish('order.cancelled', json_encode($orderData));

// Listen to all user events
Amqp::consume('user-events-queue', $callback, [
    'routing' => ['user.*'],  // Receives: user.created, user.updated, etc.
]);

// Listen to all order events
Amqp::consume('order-events-queue', $callback, [
    'routing' => ['order.*'],  // Receives: order.placed, order.cancelled, etc.
]);

// Listen to all created events (any entity)
Amqp::consume('created-events-queue', $callback, [
    'routing' => ['*.created'],  // Receives: user.created, order.created, etc.
]);
```

**Reference:** This question was asked in [GitHub Issue #88](https://github.com/bschmitt/laravel-amqp/issues/88). Wildcard routing is a standard RabbitMQ feature for topic exchanges. See the [RabbitMQ Topics Tutorial](https://www.rabbitmq.com/tutorials/tutorial-five-java) for more details.

### Q: Can I use a `listen()` method that auto-creates queues and binds to multiple routing keys?

**A:** Yes! The `listen()` method is now available in the package. It automatically creates a queue and binds it to multiple routing keys, making it easy to subscribe to multiple routing patterns.

**The `listen()` Method:**

The package now provides the exact API that was requested:
```php
Amqp::listen('routing.key,other.key', callback);
```

This automatically:
1. Creates a queue behind the scenes (or uses a provided queue name)
2. Binds it to multiple routing keys
3. Consumes from that queue

**Basic Usage:**

You can achieve this by:
1. Generating a unique queue name (or using an empty string for auto-generated names)
2. Passing multiple routing keys as an array
3. Using `consume()` as normal

```php
use Bschmitt\Amqp\Facades\Amqp;

// Listen to multiple routing keys with comma-separated string
Amqp::listen('routing.key,other.key', function ($message, $resolver) {
    echo "Received: " . $message->body . "\n";
    $resolver->acknowledge($message);
}, [
    'exchange' => 'my-exchange',
    'exchange_type' => 'topic',
]);

// Or with array of routing keys
Amqp::listen(['routing.key', 'other.key', 'third.key'], function ($message, $resolver) {
    echo "Received: " . $message->body . "\n";
    $resolver->acknowledge($message);
}, [
    'exchange' => 'my-exchange',
    'exchange_type' => 'topic',
]);
```

**Features:**

- **Auto-Generated Queue Names**: If you don't provide a queue name, it automatically generates one (e.g., `listener-693a5dae9d88c7.63247176`)
- **Custom Queue Names**: You can provide your own queue name if needed
- **Auto-Delete Queues**: By default, queues are set to auto-delete when the consumer disconnects
- **Default Exchange Type**: Defaults to `topic` exchange type if not specified
- **Multiple Routing Keys**: Supports both comma-separated strings and arrays

**Example with Custom Queue:**

```php
Amqp::listen('routing.key,other.key', function ($message, $resolver) {
    $resolver->acknowledge($message);
}, [
    'queue' => 'my-custom-queue',  // Use custom queue name
    'exchange' => 'my-exchange',
    'exchange_type' => 'topic',
    'queue_auto_delete' => false,  // Keep queue after disconnect
]);
```

**Example: Artisan Command for Persistent Listeners**

```php
// app/Console/Commands/ListenToRoutingKeys.php
use Illuminate\Console\Command;
use Bschmitt\Amqp\Facades\Amqp;

class ListenToRoutingKeys extends Command
{
    protected $signature = 'amqp:listen {routing-keys} {--exchange=} {--exchange-type=topic}';
    protected $description = 'Listen to multiple routing keys with auto-generated queue';

    public function handle()
    {
        $routingKeys = $this->argument('routing-keys');
        $exchange = $this->option('exchange') ?: config('amqp.properties.production.exchange');
        $exchangeType = $this->option('exchange-type');
        
        $this->info("Listening to: {$routingKeys}");
        $this->info("Exchange: {$exchange}");
        
        Amqp::listen($routingKeys, function ($message, $resolver) {
            $this->info("Received: " . $message->body);
            $this->info("Routing Key: " . $message->getRoutingKey());
            $resolver->acknowledge($message);
        }, [
            'exchange' => $exchange,
            'exchange_type' => $exchangeType,
            'persistent' => true,
        ]);
    }
}
```

**Usage:**

```bash
php artisan amqp:listen "routing.key,other.key" --exchange=my-exchange
```

**Key Points:**

1. **Multiple Routing Keys**: The `routing` property accepts an array, so you can bind to multiple routing keys:
   ```php
   'routing' => ['routing.key', 'other.key', 'third.key']
   ```

2. **Auto-Generated Queue Names**: 
   - Use an empty string `''` for server-generated unique names
   - Or generate your own unique name (e.g., `'listener-' . Str::random(10)`)

3. **Queue Lifecycle**:
   - `queue_auto_delete => true` - Queue is deleted when consumer disconnects
   - `queue_exclusive => true` - Queue is exclusive to this connection
   - Use both for temporary queues that clean up automatically

**RPC Support:**

The package now includes built-in RPC support with `rpc()` and `reply()` methods. See the FAQ entry on [Request-Response Pattern](#how-do-i-implement-request-response-pattern-between-microservices-service-a--service-b--service-a) for details.

**Reference:** This feature was requested in [GitHub Issue #19](https://github.com/bschmitt/laravel-amqp/issues/19) and has been fully implemented. The `listen()` method is now available in the package.

### Q: My second publish call uses properties from the first call. Why?

**A:** This can happen if you don't provide complete configuration for each publish/consume call. The package creates a new Publisher/Consumer instance for each call, but properties are merged with your base config.

**The Problem:**

```php
// First call
Amqp::publish('task_share', $payload, [
    'exchange_type' => 'fanout',
    'exchange' => 'shared'
]);

// Second call - might inherit 'exchange' from config if not specified
Amqp::publish('image', $payload, [
    'queue' => 'tasks'
    // Missing 'exchange' - might use default or previous value
]);
```

**Solution: Always Provide Complete Configuration**

For each publish/consume call, explicitly specify all properties you need:

```php
// First call - explicit
Amqp::publish('task_share', $payload, [
    'exchange' => 'shared',
    'exchange_type' => 'fanout',
    'routing' => ['task_share'],
]);

// Second call - explicit (don't rely on defaults)
Amqp::publish('image', $payload, [
    'exchange' => 'amq.topic',  // Explicitly set
    'exchange_type' => 'topic',  // Explicitly set
    'queue' => 'tasks',
    'routing' => ['image'],
]);
```

**Best Practice: Use Configuration Profiles**

Define different configuration profiles in `config/amqp.php`:

```php
'properties' => [
    'production' => [
        // Base config
    ],
    'fanout-publisher' => [
        'exchange' => 'shared',
        'exchange_type' => 'fanout',
    ],
    'queue-publisher' => [
        'exchange' => 'amq.topic',
        'exchange_type' => 'topic',
    ],
],
```

Then use them:

```php
// Use specific profile
Amqp::publish('task_share', $payload, [
    'use' => 'fanout-publisher',  // Use fanout profile
    'routing' => ['task_share'],
]);

Amqp::publish('image', $payload, [
    'use' => 'queue-publisher',  // Use queue profile
    'queue' => 'tasks',
    'routing' => ['image'],
]);
```

**How It Works:**

- Each `publish()` or `consume()` call creates a **new** Publisher/Consumer instance
- Properties you pass are **merged** with your base configuration
- If you don't specify a property, it uses the value from your base config
- This is by design to allow flexible configuration while maintaining defaults

**Important Notes:**

- Always specify `exchange` and `exchange_type` if they differ from your defaults
- The package doesn't cache Publisher/Consumer instances between calls
- Properties are merged, not replaced, so be explicit about what you want
- Consider using different config profiles for different use cases

**Reference:** This behavior was discussed in [GitHub Issue #95](https://github.com/bschmitt/laravel-amqp/issues/95). The package creates new instances for each call, but you should provide complete configuration to avoid unexpected behavior.

### Q: How do I set message priority?

**A:** First, your queue needs to support priority:

```php
'queue_properties' => [
    'x-max-priority' => 10,  // Maximum priority level
],
```

Then publish with priority:

```php
Amqp::publish('routing.key', 'message', [
    'priority' => 5,  // Higher = more important
]);
```

### Q: Can I add custom headers to messages?

**A:** Yes! Use the `application_headers` property:

```php
Amqp::publish('routing.key', 'message', [
    'application_headers' => [
        'x-user-id' => 12345,
        'x-source' => 'api',
        'x-timestamp' => time(),
    ],
]);
```

---

## Consuming Messages

### Q: My consumer isn't receiving messages. Why?

**A:** Check these things:

1. **Queue name correct?** Make sure you're consuming from the right queue
2. **Queue has messages?** Check the RabbitMQ management UI
3. **Binding correct?** Verify the queue is bound to the exchange with the right routing key
4. **Consumer running?** Make sure your consumer process is actually running
5. **Timeout too short?** If using timeout, make sure it's long enough

### Q: How do I consume messages forever (like a daemon)?

**A:** Set `persistent` to `true` and `timeout` to `0`:

```php
Amqp::consume('queue-name', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'persistent' => true,
    'timeout' => 0,  // No timeout = run forever
]);
```

### Q: How do I create a Laravel Artisan command to consume messages?

**A:** This is a common question! Unlike Laravel's built-in queue system (which uses `php artisan queue:work`), this package requires you to create a custom Artisan command. Here's how:

**Step 1: Create the Artisan Command**

Run this command to generate a new Artisan command:

```bash
php artisan make:command ConsumeMessages
```

This creates a file at `app/Console/Commands/ConsumeMessages.php`.

**Step 2: Write the Consumer Logic**

Edit the generated command file:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Bschmitt\Amqp\Facades\Amqp;

class ConsumeMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amqp:consume {queue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume messages from RabbitMQ queue';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $queueName = $this->argument('queue');
        
        $this->info("Starting to consume messages from queue: {$queueName}");

        Amqp::consume($queueName, function ($message, $resolver) {
            try {
                // Decode the message body
                $data = json_decode($message->body, true);
                
                // Process your message here
                $this->processMessage($data);
                
                // Acknowledge the message after successful processing
                $resolver->acknowledge($message);
                
                $this->info('Message processed successfully');
            } catch (\Exception $e) {
                $this->error('Error processing message: ' . $e->getMessage());
                
                // Reject and requeue for retry
                $resolver->reject($message, true);
            }
        }, [
            'persistent' => true,  // Keep consuming
            'timeout' => 0,  // No timeout = run forever
        ]);

        return 0;
    }

    /**
     * Process the message
     *
     * @param array $data
     * @return void
     */
    private function processMessage(array $data)
    {
        // Your business logic here
        // Example: Send email, update database, call API, etc.
        \Log::info('Processing message', $data);
    }
}
```

**Step 3: Run the Command**

Now you can run your consumer:

```bash
# Consume from a specific queue
php artisan amqp:consume my-queue-name
```

**For Production: Use a Process Manager**

In production, you should use a process manager like Supervisor to keep the command running:

**Install Supervisor (Ubuntu/Debian):**

```bash
sudo apt-get install supervisor
```

**Create Supervisor Configuration:**

Create `/etc/supervisor/conf.d/amqp-consumer.conf`:

```ini
[program:amqp-consumer]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/project/artisan amqp:consume my-queue-name
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/your/project/storage/logs/amqp-consumer.log
stopwaitsecs=3600
```

**Start Supervisor:**

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start amqp-consumer:*
```

**Alternative: Using Systemd (Linux)**

Create `/etc/systemd/system/amqp-consumer.service`:

```ini
[Unit]
Description=AMQP Message Consumer
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/your/project
ExecStart=/usr/bin/php artisan amqp:consume my-queue-name
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Then enable and start:

```bash
sudo systemctl enable amqp-consumer
sudo systemctl start amqp-consumer
```

**Advanced Example: Multiple Queues**

You can create separate commands for different queues, or make one command handle multiple queues:

```php
protected $signature = 'amqp:consume {queue} {--timeout=0}';

public function handle()
{
    $queueName = $this->argument('queue');
    $timeout = (int) $this->option('timeout');
    
    $this->info("Consuming from queue: {$queueName}");

    Amqp::consume($queueName, function ($message, $resolver) {
        $data = json_decode($message->body, true);
        
        // Process message
        $this->processMessage($data);
        
        $resolver->acknowledge($message);
    }, [
        'persistent' => true,
        'timeout' => $timeout,
    ]);
}
```

**Using Dependency Injection (Recommended)**

For better testability, use dependency injection:

```php
use Bschmitt\Amqp\Core\Amqp;

class ConsumeMessages extends Command
{
    protected $signature = 'amqp:consume {queue}';
    protected $description = 'Consume messages from RabbitMQ queue';

    public function handle(Amqp $amqp)
    {
        $queueName = $this->argument('queue');
        
        $amqp->consume($queueName, function ($message, $resolver) {
            // Process message
            $resolver->acknowledge($message);
        }, [
            'persistent' => true,
            'timeout' => 0,
        ]);
    }
}
```

**Key Points:**

1. **The command runs continuously** - Unlike `queue:work`, your command will run until stopped
2. **Use a process manager** - Supervisor or systemd ensures it restarts if it crashes
3. **Handle errors** - Use try-catch and reject/requeue failed messages
4. **Log everything** - Use Laravel's logging for debugging
5. **Multiple workers** - Run multiple instances for better throughput

**Reference:** This question was asked in [GitHub Issue #75](https://github.com/bschmitt/laravel-amqp/issues/75). Unlike Laravel's built-in queue system, this package requires creating custom Artisan commands for consuming messages.

### Q: My IDE warns "Non-static method 'consume' should not be called statically". Is this a problem?

**A:** This is just an IDE warning, not an actual error. The `Amqp` facade works correctly when called statically. However, if you want to avoid the warning and follow Laravel best practices, you can use dependency injection instead:

**Option 1: Using the Facade (Current - Works Fine)**

```php
use Bschmitt\Amqp\Facades\Amqp;

Amqp::consume('queue-name', function ($message, $resolver) {
    $resolver->acknowledge($message);
});
```

This works perfectly fine - the warning is just your IDE not recognizing Laravel's facade pattern.

**Option 2: Using Dependency Injection (Recommended for Controllers/Commands)**

Inject the `Amqp` class via dependency injection:

```php
use Bschmitt\Amqp\Core\Amqp;
use Illuminate\Http\Request;

class MyController extends Controller
{
    public function consumeMessages(Request $request, Amqp $amqp)
    {
        $amqp->consume('queue-name', function ($message, $resolver) {
            // Process message
            $resolver->acknowledge($message);
            $resolver->stopWhenProcessed();
        });
    }
}
```

Or in a Laravel Artisan command:

```php
use Bschmitt\Amqp\Core\Amqp;

class ConsumeMessagesCommand extends Command
{
    public function handle(Amqp $amqp)
    {
        $amqp->consume('queue-name', function ($message, $resolver) {
            // Process message
            $resolver->acknowledge($message);
            $resolver->stopWhenProcessed();
        });
    }
}
```

**Why use dependency injection?**
-  No IDE warnings
-  Better testability (easier to mock)
-  Follows Laravel best practices
-  Explicit dependencies (clearer code)

**Why facades are still fine:**
-  Simpler syntax
-  Works everywhere (not just dependency injection contexts)
-  Common Laravel pattern
-  The warning doesn't affect functionality

**Note:** Both approaches work identically. Use whichever fits your coding style and project requirements.

**Reference:** This question was asked in [GitHub Issue #114](https://github.com/bschmitt/laravel-amqp/issues/114).

### Q: What's the difference between acknowledge and reject?

**A:** Good question!

- **Acknowledge (`ack`)**: Message was processed successfully, remove it from the queue
- **Reject**: Message processing failed
  - `reject($message, true)` - Requeue the message (try again)
  - `reject($message, false)` - Don't requeue (send to dead letter exchange if configured)

### Q: How do I access all messages from a queue and process them?

**A:** There are several approaches depending on your needs. Here are the most common patterns:

**Approach 1: Collect All Messages, Then Process (Batch Processing)**

This approach collects all messages first, then processes them in one go. Useful when you need to process everything together (e.g., send all notifications at once):

```php
use Bschmitt\Amqp\Facades\Amqp;

class NotificationController extends Controller
{
    private $messageBatch = [];

    public function processAllMessages()
    {
        // Collect all messages
        Amqp::consume('notification-queue', function ($message, $resolver) {
            // Add message to batch
            $this->messageBatch[] = [
                'body' => $message->body,
                'routing_key' => $message->getRoutingKey(),
                'headers' => $message->getHeaders(),
            ];
            
            // Acknowledge the message
            $resolver->acknowledge($message);
            
            // Stop after processing all messages
            $resolver->stopWhenProcessed();
        }, [
            'timeout' => 10,  // Timeout after 10 seconds of no new messages
        ]);

        // Process all collected messages
        $this->sendToDashboard();
        $this->sendViaSms();
        
        return response()->json([
            'processed' => count($this->messageBatch),
            'messages' => $this->messageBatch,
        ]);
    }

    private function sendToDashboard()
    {
        foreach ($this->messageBatch as $message) {
            // Send to dashboard
            // Dashboard::send($message);
        }
    }

    private function sendViaSms()
    {
        foreach ($this->messageBatch as $message) {
            // Send SMS
            // SmsService::send($message);
        }
    }
}
```

**Approach 2: Process Messages One by One (Streaming)**

This approach processes each message as it arrives. Better for real-time processing:

```php
Amqp::consume('notification-queue', function ($message, $resolver) {
    $data = json_decode($message->body, true);
    
    // Process immediately
    $this->sendToDashboard($data);
    $this->sendViaSms($data);
    
    $resolver->acknowledge($message);
}, [
    'persistent' => true,  // Keep consuming
    'timeout' => 0,  // No timeout = run forever
]);
```

**Approach 3: Process in Batches with Limit**

Process messages in batches of a specific size:

```php
class BatchProcessor
{
    private $batch = [];
    private $batchSize = 10;

    public function processInBatches()
    {
        Amqp::consume('notification-queue', function ($message, $resolver) {
            $this->batch[] = $message;
            
            // Process batch when it reaches the limit
            if (count($this->batch) >= $this->batchSize) {
                $this->processBatch();
                $this->batch = [];  // Reset batch
            }
            
            $resolver->acknowledge($message);
        }, [
            'timeout' => 30,
        ]);

        // Process remaining messages
        if (!empty($this->batch)) {
            $this->processBatch();
        }
    }

    private function processBatch()
    {
        // Send all messages in batch to dashboard
        foreach ($this->batch as $message) {
            $this->sendToDashboard($message->body);
        }
        
        // Send all messages in batch via SMS
        foreach ($this->batch as $message) {
            $this->sendViaSms($message->body);
        }
    }
}
```

**Approach 4: Using Queue Purge (Get All Messages at Once)**

If you want to get all existing messages from a queue and process them:

```php
// First, get queue statistics to see how many messages
$stats = Amqp::getQueueStats('notification-queue');
$messageCount = $stats['messages'] ?? 0;

// Consume all messages
$messages = [];
Amqp::consume('notification-queue', function ($message, $resolver) use (&$messages) {
    $messages[] = [
        'body' => $message->body,
        'properties' => [
            'routing_key' => $message->getRoutingKey(),
            'correlation_id' => $message->getCorrelationId(),
            'headers' => $message->getHeaders(),
        ],
    ];
    
    $resolver->acknowledge($message);
    
    // Stop when queue is empty (no more messages)
    $resolver->stopWhenProcessed();
}, [
    'timeout' => 60,  // Wait up to 60 seconds
]);

// Now process all collected messages
foreach ($messages as $msg) {
    $this->sendToDashboard($msg['body']);
    $this->sendViaSms($msg['body']);
}
```

**Important Considerations:**

1. **Message Acknowledgment**: Always acknowledge messages after processing to remove them from the queue
2. **Error Handling**: Handle errors to avoid losing messages:
   ```php
   Amqp::consume('queue', function ($message, $resolver) {
       try {
           $this->processMessage($message);
           $resolver->acknowledge($message);
       } catch (\Exception $e) {
           \Log::error('Failed to process message: ' . $e->getMessage());
           // Requeue for retry
           $resolver->reject($message, true);
       }
   });
   ```

3. **Timeout**: Use appropriate timeouts to avoid infinite waiting
4. **Memory**: For large queues, consider processing in batches rather than loading everything into memory
5. **Idempotency**: Make your processing idempotent (safe to retry) in case of failures

**Best Practice: Use Laravel Queue Workers**

For production, consider using Laravel's queue workers:

```php
// In a Laravel command or queue worker
php artisan queue:work --queue=notification-queue

// Or in your code
use Illuminate\Queue\Jobs\Job;

class ProcessNotificationsJob implements ShouldQueue
{
    public function handle()
    {
        Amqp::consume('notification-queue', function ($message, $resolver) {
            $data = json_decode($message->body, true);
            
            // Process and send
            $this->sendToDashboard($data);
            $this->sendViaSms($data);
            
            $resolver->acknowledge($message);
        });
    }
}
```

**Reference:** This question was asked in [GitHub Issue #87](https://github.com/bschmitt/laravel-amqp/issues/87). The approach you choose depends on whether you need real-time processing or batch processing.

### Q: What happens if I don't acknowledge a message? Will it be lost?

**A:** This is a critical question for message reliability! Here's what you need to know:

**If you don't acknowledge a message:**
- The message will **NOT be lost** - it remains in the queue
- RabbitMQ will **redeliver** the message to another consumer (or the same consumer if it reconnects)
- The message stays in "unacknowledged" state until it's either:
  - Acknowledged (removed from queue)
  - Rejected (handled according to reject settings)
  - The connection closes (message is automatically requeued)

**For your email sending use case**, here's the correct pattern:

```php
Amqp::consume('communication-sent', function ($message, $resolver) use ($mailController) {
    $messageDecoded = json_decode($message->body);
    $communication = $messageDecoded->communication;
    $emailAddress = $messageDecoded->email;

    print('Sending mail to ' . $emailAddress . " [communication]\n");
    
    try {
        $mailController->send($emailAddress, new CommunicationSent($communication));
        print('Sending successful\n');
        
        //  Acknowledge ONLY after successful processing
        $resolver->acknowledge($message);
    } catch (\Exception $e) {
        print("Error: " . $e->getMessage());
        
        // ❌ Don't acknowledge on error - message will be redelivered
        // Optionally, you can reject with requeue:
        // $resolver->reject($message, true);  // Requeue for retry
        // Or reject without requeue (send to DLX if configured):
        // $resolver->reject($message, false);  // Don't requeue
    }
    
    $resolver->stopWhenProcessed();
});
```

**Key points:**
1. **Move `acknowledge()` inside the `try` block** - Only acknowledge after successful processing
2. **Don't acknowledge on errors** - If an exception occurs, the message stays unacknowledged and will be redelivered
3. **The message will be redelivered** - RabbitMQ will automatically requeue unacknowledged messages when the connection closes or after a timeout
4. **You can control retry behavior** - Use `reject($message, true)` to explicitly requeue, or `reject($message, false)` to send to dead letter exchange

**Important:** If your consumer crashes or the connection drops, all unacknowledged messages are automatically requeued and will be delivered again. This ensures no messages are lost, but you need to handle duplicate processing (make your operations idempotent).

**Reference:** This question was asked in [GitHub Issue #122](https://github.com/bschmitt/laravel-amqp/issues/122).

### Q: How do I control how many messages a consumer gets at once?

**A:** Use QoS (Quality of Service) settings:

```php
Amqp::consume('queue-name', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'qos' => true,
    'qos_prefetch_count' => 10,  // Max 10 unacknowledged messages
]);
```

This ensures fair distribution when you have multiple consumers.

### Q: How do I implement request-response pattern between microservices (Service A → Service B → Service A)?

**A:** The package provides built-in RPC support with `rpc()` and `reply()` methods, making request-response patterns easy to implement. Here are multiple approaches:

**The Pattern:**
1. Service A publishes a request with a `correlation_id` and `reply_to` queue
2. Service B processes the request and sends a response with the same `correlation_id`
3. Service A consumes from the reply queue and matches responses using `correlation_id`

**Service A (Request Publisher & Response Consumer):**

```php
use Bschmitt\Amqp\Facades\Amqp;
use Illuminate\Support\Str;

class ApiController extends Controller
{
    public function callServiceB(Request $request)
    {
        // Generate unique correlation ID
        $correlationId = Str::uuid()->toString();
        
        // Create a unique reply queue for this request
        $replyQueue = 'reply-' . $correlationId;
        
        // Store response (in memory, cache, or database)
        $responseStore = [];
        
        // Set up response consumer BEFORE publishing
        $responseReceived = false;
        $response = null;
        
        Amqp::consume($replyQueue, function ($message, $resolver) use (&$responseReceived, &$response, $correlationId) {
            // Check if this is the response we're waiting for
            if ($message->getCorrelationId() === $correlationId) {
                $response = json_decode($message->body, true);
                $responseReceived = true;
                $resolver->acknowledge($message);
                $resolver->stopWhenProcessed();
            } else {
                // Not our response, requeue it
                $resolver->reject($message, true);
            }
        }, [
            'queue' => $replyQueue,
            'timeout' => 30,  // Wait up to 30 seconds for response
        ]);
        
        // Publish request to Service B
        Amqp::publish('service-b.request', json_encode([
            'data' => $request->all(),
        ]), [
            'correlation_id' => $correlationId,
            'reply_to' => $replyQueue,
            'exchange' => 'requests',
            'routing' => ['service-b.request'],
        ]);
        
        // Wait for response (with timeout)
        $timeout = 30;
        $startTime = time();
        while (!$responseReceived && (time() - $startTime) < $timeout) {
            usleep(100000); // Sleep 100ms
        }
        
        if (!$responseReceived) {
            return response()->json(['error' => 'Service B timeout'], 504);
        }
        
        return response()->json($response);
    }
}
```

**Service B (Request Consumer & Response Publisher) - Using `reply()` Method:**

```php
// In Service B - consume requests and reply
Amqp::consume('service-b-queue', function ($message, $resolver) {
    $requestData = json_decode($message->body, true);
    
    // Process the request
    $result = $this->processRequest($requestData);
    
    // Reply using the built-in reply() method (automatically handles correlation_id and reply_to)
    $resolver->reply($message, json_encode($result));
    
    $resolver->acknowledge($message);
}, [
    'queue' => 'service-b-queue',
    'routing' => ['service-b.request'],
]);
```

**Service B (Alternative - Manual Approach):**

```php
// In Service B - consume requests
Amqp::consume('service-b-queue', function ($message, $resolver) {
    $requestData = json_decode($message->body, true);
    $correlationId = $message->getCorrelationId();
    $replyTo = $message->getReplyTo();
    
    // Process the request
    $result = $this->processRequest($requestData);
    
    // Send response back to Service A
    if ($replyTo) {
        Amqp::publish('', json_encode($result), [
            'correlation_id' => $correlationId,  // Match the request
            'queue' => $replyTo,
            'routing' => [''],
        ]);
    }
    
    $resolver->acknowledge($message);
}, [
    'queue' => 'service-b-queue',
    'routing' => ['service-b.request'],
]);
```

**Better Approach: Using a Shared Reply Queue**

Instead of creating a unique queue per request, use a shared reply queue with correlation IDs:

```php
// Service A - Controller
public function callServiceB(Request $request)
{
    $correlationId = Str::uuid()->toString();
    $replyQueue = 'service-a-replies';  // Shared queue
    
    // Store pending requests (use cache or in-memory)
    Cache::put("pending_request_{$correlationId}", true, 60);
    
    $response = null;
    $responseReceived = false;
    
    // Start consumer in background or use async approach
    // For synchronous API response, use a simpler approach:
    
    // Publish request
    Amqp::publish('service-b.request', json_encode($request->all()), [
        'correlation_id' => $correlationId,
        'reply_to' => $replyQueue,
    ]);
    
    // Consume with timeout
    Amqp::consume($replyQueue, function ($message, $resolver) use (&$response, &$responseReceived, $correlationId) {
        if ($message->getCorrelationId() === $correlationId) {
            $response = json_decode($message->body, true);
            $responseReceived = true;
            $resolver->acknowledge($message);
            $resolver->stopWhenProcessed();
        } else {
            // Not our message, requeue
            $resolver->reject($message, true);
        }
    }, [
        'timeout' => 30,
    ]);
    
    if ($responseReceived) {
        return response()->json($response);
    }
    
    return response()->json(['error' => 'Timeout'], 504);
}
```

**Alternative: Asynchronous Approach (Recommended for Long Operations)**

For long-running operations, use an asynchronous pattern:

```php
// Service A - Publish and return immediately
public function callServiceB(Request $request)
{
    $requestId = Str::uuid()->toString();
    
    // Store request status
    Cache::put("request_{$requestId}", ['status' => 'pending'], 300);
    
    // Publish request
    Amqp::publish('service-b.request', json_encode([
        'request_id' => $requestId,
        'data' => $request->all(),
    ]), [
        'correlation_id' => $requestId,
        'reply_to' => 'service-a-replies',
    ]);
    
    // Return request ID to client
    return response()->json([
        'request_id' => $requestId,
        'status' => 'processing',
        'check_url' => "/api/status/{$requestId}",
    ], 202);  // 202 Accepted
}

// Separate endpoint to check status
public function checkStatus($requestId)
{
    $status = Cache::get("request_{$requestId}");
    
    if (!$status) {
        return response()->json(['error' => 'Request not found'], 404);
    }
    
    return response()->json($status);
}

// Background worker consumes responses
// In a queue worker or scheduled task
Amqp::consume('service-a-replies', function ($message, $resolver) {
    $correlationId = $message->getCorrelationId();
    $response = json_decode($message->body, true);
    
    // Update status in cache
    Cache::put("request_{$correlationId}", [
        'status' => 'completed',
        'response' => $response,
    ], 300);
    
    $resolver->acknowledge($message);
}, [
    'persistent' => true,
]);
```

**Key Points:**

1. **Correlation ID** - Unique identifier to match requests with responses
2. **Reply To** - Queue where the response should be sent
3. **Timeout Handling** - Always set timeouts to avoid hanging requests
4. **Message Matching** - Always check `correlation_id` to ensure you get the right response
5. **Queue Cleanup** - Delete temporary reply queues after use (or use shared queues)

**Best Practices:**

- Use **shared reply queues** instead of unique queues per request
- Set **appropriate timeouts** based on your service response time
- Consider **asynchronous patterns** for long-running operations
- **Always check correlation_id** to match responses correctly
- Use **cache or database** to store request status for async patterns

**Built-in RPC Methods:**

The package now includes `rpc()` and `reply()` methods for easier RPC implementation. See the examples above for usage.

**Reference:** This question was asked in [GitHub Issue #90](https://github.com/bschmitt/laravel-amqp/issues/90). The package now includes built-in RPC support with `rpc()` and `reply()` methods.

---

## Queue Configuration

### Q: How do I bind a queue to an exchange manually (like `queue_bind` in php-amqplib)?

**A:** The Laravel AMQP package handles queue binding automatically when you use the `routing` property in your configuration. However, if you need to manually bind a queue to an exchange (especially for fanout exchanges), here are your options:

**Option 1: Use the `routing` property (Recommended)**

When you publish or consume with a queue specified, the package automatically binds the queue to the exchange using the routing keys you provide:

```php
// In your config/amqp.php or when publishing/consuming
Amqp::publish('routing.key', 'message', [
    'queue' => 'my-queue',
    'routing' => ['routing.key'],  // This binds the queue to the exchange
    'exchange' => 'my-exchange',
]);
```

For **fanout exchanges**, you can use an empty routing key (fanout exchanges ignore routing keys anyway):

```php
Amqp::publish('', 'message', [
    'queue' => 'my-queue',
    'routing' => [''],  // Empty routing key works for fanout
    'exchange' => 'my-fanout-exchange',
    'exchange_type' => 'fanout',
]);
```

**Option 2: Access the channel directly (Advanced)**

If you really need direct access to `queue_bind()`, you can access the underlying channel:

```php
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Core\Publisher;

// Create a publisher instance to get the channel
$publisher = app(Publisher::class);
$publisher->setup(['exchange' => 'my-exchange', 'exchange_type' => 'fanout']);

// Get the channel and bind manually
$channel = $publisher->getChannel();
$channel->queue_bind('my-queue', 'my-exchange', '');  // Empty routing key for fanout

// Don't forget to clean up
$publisher->disconnect();
```

**Note:** The package automatically binds queues when you specify the `routing` property, so manual binding is usually not necessary. For fanout exchanges, just use an empty routing key in the `routing` array.

**Reference:** This question was asked in [GitHub Issue #124](https://github.com/bschmitt/laravel-amqp/issues/124).

### Q: How do I set up a dead letter exchange?

**A:** Configure it in your queue properties:

```php
'queue_properties' => [
    'x-dead-letter-exchange' => 'dlx-exchange',
    'x-dead-letter-routing-key' => 'dlx.key',  // Optional
],
```

Messages that are rejected (without requeue) or expire will go to the dead letter exchange.

### Q: What's the difference between classic, quorum, and stream queues?

**A:** Here's the quick version:

- **Classic**: The default, traditional RabbitMQ queue
- **Quorum**: High availability, better for clusters, recommended for new apps
- **Stream**: Append-only log, great for high throughput and replay

Set it with:

```php
'queue_properties' => [
    'x-queue-type' => 'quorum',  // or 'classic' or 'stream'
],
```

### Q: How do I make messages expire after a certain time?

**A:** Use message TTL:

```php
'queue_properties' => [
    'x-message-ttl' => 60000,  // 60 seconds in milliseconds
],
```

Messages older than this will be removed or sent to the dead letter exchange.

### Q: How do I set a maximum queue length to prevent message buildup?

**A:** You can limit the number of messages in a queue using `x-max-length`. This is perfect for scenarios where you publish messages frequently but only need the latest message.

**For your use case** (publishing every 2 minutes, keeping only the latest message):

```php
// In config/amqp.php
'queue_properties' => [
    'x-max-length' => 1,  // Keep only 1 message (the latest)
],
```

Or when publishing/consuming:

```php
Amqp::publish('routing.key', 'message', [
    'queue' => 'my-queue',
    'queue_properties' => [
        'x-max-length' => 1,  // Only keep the latest message
    ],
]);
```

**How it works:**
- When the queue reaches the max length, RabbitMQ drops the **oldest** message (default behavior: `drop-head`)
- New messages are added normally
- Only the most recent messages (up to your limit) remain in the queue
- This prevents message buildup when consumers are slow or offline

**Additional options:**

You can also limit by **size in bytes**:

```php
'queue_properties' => [
    'x-max-length-bytes' => 1048576,  // 1MB max size
],
```

Or set both limits (whichever is hit first applies):

```php
'queue_properties' => [
    'x-max-length' => 100,           // Max 100 messages
    'x-max-length-bytes' => 1048576, // OR max 1MB
],
```

**Control overflow behavior:**

You can change what happens when the limit is reached:

```php
'queue_properties' => [
    'x-max-length' => 1,
    'x-overflow' => 'drop-head',  // Default: drop oldest messages
    // OR
    'x-overflow' => 'reject-publish',  // Reject new publishes
    // OR
    'x-overflow' => 'reject-publish-dlx',  // Reject and send to dead letter exchange
],
```

**Important notes:**
- `x-max-length` counts only **ready** messages (unacknowledged messages don't count)
- The default behavior (`drop-head`) automatically removes the oldest messages
- This is perfect for keeping only the latest state/update when consumers are slow

**Reference:** This question was asked in [GitHub Issue #120](https://github.com/bschmitt/laravel-amqp/issues/120). See also the [Maximum Queue Length documentation](../modules/RABBITMQ_MAXLENGTH_SUPPORT.md).

---

## Advanced Features

### Q: What are publisher confirms and do I need them?

**A:** Publisher confirms tell you when RabbitMQ has actually received your message. You should use them in production:

```php
Amqp::publish('routing.key', 'message', [
    'publisher_confirms' => true,
    'wait_for_confirms' => true,
]);
```

This ensures your messages are actually delivered, not just sent.

### Q: How do I use the Management API?

**A:** First, configure it in your config:

```php
'management_host' => 'http://localhost',
'management_port' => 15672,
'management_username' => 'guest',
'management_password' => 'guest',
```

Then use it:

```php
$stats = Amqp::getQueueStats('queue-name');
$connections = Amqp::getConnections();
$policies = Amqp::listPolicies();
```

### Q: Can I delete queues programmatically?

**A:** Yes! Use the management methods:

```php
// Delete queue (only if empty and unused)
Amqp::queueDelete('queue-name', [
    'if_empty' => true,
    'if_unused' => true,
]);

// Or just delete it
Amqp::queueDelete('queue-name');
```

---

## Troubleshooting

### Q: I'm getting "PRECONDITION_FAILED" errors. What does this mean?

**A:** This usually means the queue or exchange already exists with different properties. Solutions:

1. **Delete and recreate** - Remove the queue/exchange in RabbitMQ management UI
2. **Match properties** - Make sure your config matches the existing queue properties
3. **Use different names** - Use unique names for testing

### Q: I'm getting "PRECONDITION_FAILED - inequivalent arg 'x-queue-type'" error. How do I fix it?

**A:** This error occurs when a queue already exists with a specific queue type (like `quorum` or `classic`), but when code is trying to declare it without specifying the type or with a different type.

**The Error:**
```
PRECONDITION_FAILED - inequivalent arg 'x-queue-type' for queue 'my-queue' in vhost '/': 
received none but current is the value 'quorum' of type 'longstr'
```

This means the queue `my-queue` already exists as a `quorum` queue, but your code is trying to declare it without specifying the queue type (or as a different type).

**Solution 1: Match the Existing Queue Type (Recommended)**

Set the `x-queue-type` in your `queue_properties` to match the existing queue:

```php
// In config/amqp.php
'queue_properties' => [
    'x-queue-type' => 'quorum',  // Match the existing queue type
],
```

Or when publishing/consuming:

```php
Amqp::consume('my-queue', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'queue_properties' => [
        'x-queue-type' => 'quorum',  // Must match existing queue type
    ],
]);
```

**Solution 2: Delete and Recreate the Queue**

If you want to change the queue type, you must delete the existing queue first:

1. **Via RabbitMQ Management UI:**
   - Go to `http://localhost:15672`
   - Navigate to Queues
   - Delete the queue
   - Your code will recreate it with the new type

2. **Via Management API:**
   ```php
   Amqp::queueDelete('my-queue');
   ```

3. **Then declare with the desired type:**
   ```php
   'queue_properties' => [
       'x-queue-type' => 'classic',  // Or 'quorum', 'stream'
   ],
   ```

**Solution 3: Use Passive Queue Declaration**

If you just want to consume from an existing queue without declaring it:

```php
Amqp::consume('my-queue', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'queue_passive' => true,  // Don't declare, just use existing queue
]);
```

**Available Queue Types:**

- `'classic'` - Default, traditional RabbitMQ queue
- `'quorum'` - High availability queue with automatic replication
- `'stream'` - Append-only log queue for high throughput

**Important Notes:**

- **Queue type cannot be changed** - Once a queue is created with a type, you must delete and recreate it to change the type
- **Always specify the type** - If a queue already exists with a type, your config must match it
- **Check existing queues** - Use Management UI or API to check what type your queues are:
  ```php
  $stats = Amqp::getQueueStats('my-queue');
  // Check queue properties in RabbitMQ Management UI
  ```

**Example: Fixing the Error**

If you have a queue that's already `quorum` type:

```php
// config/amqp.php
'queue_properties' => [
    'x-queue-type' => 'quorum',  // Must match existing queue
    // Other properties...
],
```

Or if you want to use a classic queue instead:

1. Delete the existing queue (via Management UI or API)
2. Then use:
   ```php
   'queue_properties' => [
       'x-queue-type' => 'classic',  // Now you can create as classic
   ],
   ```

**Reference:** This question was asked in [GitHub Issue #110](https://github.com/bschmitt/laravel-amqp/issues/110). See also the [Queue Types documentation](../modules/QUEUE_TYPE.md) for more information.

### Q: Messages are piling up in my queue. What should I do?

**A:** A few options:

1. **Scale up consumers** - Add more worker processes
2. **Check consumer health** - Make sure consumers are actually processing
3. **Increase prefetch** - Allow consumers to get more messages at once
4. **Use Management API** - Monitor queue length: `Amqp::getQueueStats('queue-name')`
5. **Set TTL** - Auto-expire old messages

### Q: My tests are failing. How do I debug?

**A:** Here's a debugging checklist:

1. **Is RabbitMQ running?** Check with `docker ps`
2. **Check connection** - Verify host, port, credentials
3. **Clean state** - Delete test queues/exchanges between tests
4. **Check logs** - Look at RabbitMQ logs for errors
5. **Use Management UI** - Check what's actually in RabbitMQ

### Q: Can I use this package outside of Laravel?

**A:** Yes! The package can work standalone, but you'll need to provide your own configuration provider. The core classes don't require Laravel, but the facades and service providers do.

### Q: Does this package support Azure Service Bus?

**A:** No, this package does not support Azure Service Bus. Here's why:

**Protocol Incompatibility:**
- **This package** uses **AMQP 0-9-1** (via php-amqplib library)
- **Azure Service Bus** uses **AMQP 1.0**
- These are **different protocols** and are not compatible with each other

**What This Means:**
- You cannot connect to Azure Service Bus using this package
- The AMQP 0-9-1 protocol used by this package is specific to RabbitMQ
- Azure Service Bus requires an AMQP 1.0 client library

**Alternatives for Azure Service Bus:**

If you need to work with Azure Service Bus, you'll need to use a different package that supports AMQP 1.0:

1. **Microsoft's Official SDK:**
   ```bash
   composer require microsoft/azure-service-bus
   ```

2. **AMQP 1.0 PHP Libraries:**
   - Look for packages that specifically support AMQP 1.0 protocol
   - Azure Service Bus has its own REST API and SDKs

**Why Not Both Protocols?**

Supporting both AMQP 0-9-1 and AMQP 1.0 would require:
- Different underlying libraries (php-amqplib vs AMQP 1.0 libraries)
- Completely different connection handling
- Different message formats and operations
- Essentially a rewrite of the entire package

**Current Focus:**

This package is specifically designed for **RabbitMQ** using the **AMQP 0-9-1** protocol, which is:
- The standard protocol for RabbitMQ
- Well-tested and stable
- Widely supported in the PHP ecosystem

**Reference:** This question was asked in [GitHub Issue #101](https://github.com/bschmitt/laravel-amqp/issues/101). The package maintainer confirmed that other protocols (like AMQP 1.0 for Azure Service Bus) are not planned for implementation.

---

## Performance

### Q: How can I improve publishing performance?

**A:** Try these:

1. **Batch publishing** - Send multiple messages at once:

```php
Amqp::batchBasicPublish('key1', 'msg1');
Amqp::batchBasicPublish('key2', 'msg2');
Amqp::batchPublish();  // Send all at once
```

2. **Disable confirms in dev** - Only enable in production
3. **Connection pooling** - Reuse connections when possible

### Q: My consumer is slow. How do I speed it up?

**A:** A few tips:

1. **Increase prefetch** - Let consumers get more messages:

```php
'qos_prefetch_count' => 20,  // Instead of 1
```

2. **Process in parallel** - Use multiple consumer processes
3. **Optimize your code** - Make sure your message processing is efficient
4. **Use lazy queues** - For large backlogs, use `x-queue-mode: lazy`

---

## Best Practices

### Q: What's the recommended way to handle errors?

**A:** Always wrap in try-catch and decide whether to requeue:

```php
Amqp::consume('queue-name', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\Exception $e) {
        \Log::error('Failed: ' . $e->getMessage());
        // Requeue for retry
        $resolver->reject($message, true);
    }
});
```

### Q: Should I use quorum queues or classic queues?

**A:** For new applications, we recommend quorum queues. They provide:
- Better high availability
- Automatic leader election
- Strong consistency
- Better performance in clusters

Classic queues are fine for simple use cases, but quorum queues are the future.

### Q: How do I monitor my queues in production?

**A:** Use the Management API:

```php
$stats = Amqp::getQueueStats('queue-name');
echo "Messages: " . $stats['messages'];
echo "Consumers: " . $stats['consumers'];
```

Or set up monitoring with the Management UI at `http://your-rabbitmq:15672`.

---

## Testing

### Q: How do I test my code that uses AMQP?

**A:** We provide comprehensive test support:

1. **Unit tests** - Mock the AMQP classes
2. **Integration tests** - Use real RabbitMQ (see [Testing](Testing) guide)
3. **Test with Docker** - Use `rabbitmq:3-management` image

All our tests are written for `rabbitmq:3-management`, so you can use the same setup.

### Q: Can I run tests without RabbitMQ?

**A:** For unit tests, yes - use mocks. For integration tests, you'll need RabbitMQ running. We recommend using Docker for consistency.

### Q: Are integration tests implemented? What do they test?

**A:** Yes! Comprehensive integration tests have been implemented. The package includes extensive integration test coverage that tests real RabbitMQ functionality.

**Integration Tests Available:**

The package includes integration tests for:

1. **Core Functionality:**
   - `FullIntegrationTest.php` - End-to-end publish/consume scenarios
   - `PublishConsumeVerificationTest.php` - Message publishing and consumption verification
   - `ConsumeAllMessagesTest.php` - Consuming all messages from queues
   - `ConsumeExistingQueueMessagesTest.php` - Consuming from existing queues

2. **Advanced Features:**
   - `PublisherConfirmsIntegrationTest.php` - Publisher confirms functionality
   - `ConsumerPrefetchIntegrationTest.php` - Consumer prefetch (QoS) settings
   - `MessagePropertiesIntegrationTest.php` - Message properties (priority, correlation_id, reply_to, headers)
   - `MessagePriorityIntegrationTest.php` - Message priority handling

3. **Exchange Features:**
   - `ExchangeTypeIntegrationTest.php` - All exchange types (topic, direct, fanout, headers)
   - `AlternateExchangeIntegrationTest.php` - Alternate exchange for unroutable messages

4. **Queue Features:**
   - `QueueTypeIntegrationTest.php` - Queue types (classic, quorum, stream)
   - `QuorumQueueIntegrationTest.php` - Quorum queues specifically
   - `StreamQueueIntegrationTest.php` - Stream queues
   - `LazyQueueIntegrationTest.php` - Lazy queues
   - `QueueTTLIntegrationTest.php` - Queue and message TTL
   - `QueueMaxLengthIntegrationTest.php` - Maximum queue length
   - `QueueMaxLengthCompleteTest.php` - Complete max length scenarios
   - `DeadLetterExchangeIntegrationTest.php` - Dead letter exchange
   - `MasterLocatorIntegrationTest.php` - Master locator for HA queues

5. **Management Operations:**
   - `ManagementIntegrationTest.php` - Queue/exchange management (unbind, purge, delete)
   - `ManagementApiIntegrationTest.php` - HTTP Management API operations

**What the Tests Cover:**

-  Publishing and consuming messages
-  All exchange types (topic, direct, fanout, headers)
-  All queue types (classic, quorum, stream)
-  Advanced RabbitMQ features (TTL, priority, dead letter exchange, etc.)
-  Management operations (unbind, purge, delete)
-  Management HTTP API
-  Message properties and headers
-  Publisher confirms
-  Consumer prefetch settings

**Running Integration Tests:**

```bash
# Run all integration tests
php vendor/bin/phpunit test/Integration/

# Run specific integration test
php vendor/bin/phpunit test/Integration/FullIntegrationTest.php

# Run with coverage
php vendor/bin/phpunit test/Integration/ --coverage-text
```

**Test Requirements:**

- RabbitMQ server running (or Docker container)
- Default connection: `localhost:5672`
- Default credentials: `guest/guest`
- Management API: `http://localhost:15672` (for Management API tests)

**Test Base Class:**

All integration tests extend `IntegrationTestBase` which provides:
- Connection setup and teardown
- Queue/exchange cleanup
- Helper methods for common operations
- Automatic skipping if RabbitMQ is unavailable

**Example Integration Test:**

```php
class MyIntegrationTest extends IntegrationTestBase
{
    public function testPublishAndConsume()
    {
        // Publish a message
        Amqp::publish('test.key', 'Hello World', [
            'exchange' => 'test-exchange',
            'exchange_type' => 'topic',
        ]);

        // Consume the message
        $received = null;
        Amqp::consume('test-queue', function ($message, $resolver) use (&$received) {
            $received = $message->body;
            $resolver->acknowledge($message);
            $resolver->stopWhenProcessed();
        }, [
            'exchange' => 'test-exchange',
            'routing' => ['test.key'],
        ]);

        $this->assertEquals('Hello World', $received);
    }
}
```

**CI/CD Integration:**

The integration tests are designed to work with CI/CD systems:
- Can be run in Docker containers
- Support environment variable configuration
- Automatically skip if RabbitMQ is unavailable
- Clean up resources after each test

**Test Coverage:**

- **Unit Tests**: ~20+ test files covering all core functionality
- **Integration Tests**: ~20+ test files covering real RabbitMQ scenarios
- **Total**: Comprehensive coverage of all package features

**Reference:** Integration tests were requested in [GitHub Issue #29](https://github.com/bschmitt/laravel-amqp/issues/29) and have been fully implemented. All tests are written with compatibility for the `rabbitmq:3-management` Docker image. See the [Testing guide](Testing) for more details.

---

## Architecture & Design

### Q: Does this package use queue-interop or amqp-interop?

**A:** No, this package does not currently use [queue-interop](https://github.com/queue-interop/queue-interop) or [amqp-interop](https://github.com/queue-interop/amqp-interop) interfaces. It uses [php-amqplib](https://github.com/php-amqplib/php-amqplib) directly, which is the official PHP library recommended by RabbitMQ.

**Current Architecture:**

- **Direct Integration**: The package uses `php-amqplib` directly without abstraction layers
- **RabbitMQ Focus**: Designed specifically for RabbitMQ using AMQP 0-9-1 protocol
- **Laravel Integration**: Built as a Laravel/Lumen wrapper around php-amqplib

**What is queue-interop?**

Queue-interop is a standardization project that provides common interfaces for message queue implementations. It allows you to:
- Switch between different queue transports (RabbitMQ, Redis, Amazon SQS, etc.)
- Use unified interfaces across different queue libraries
- Leverage implementations like [enqueue](https://github.com/php-enqueue/enqueue-dev) which supports multiple transports

**Why the Package Doesn't Use It:**

1. **Direct RabbitMQ Support**: The package is specifically designed for RabbitMQ, not multiple transports
2. **Official Library**: Uses php-amqplib, which is the official library recommended by RabbitMQ
3. **Breaking Changes**: Adopting queue-interop would be a major breaking change
4. **Simplicity**: Direct integration keeps the package simpler and more focused

**Potential Benefits of Using queue-interop:**

- **Unified Interfaces**: Could allow switching between different AMQP implementations (php-amqplib, bunny, amqp-ext)
- **Additional Features**: Some implementations support features like message delaying, unified SSL configuration, and DSN support
- **Interoperability**: Could make it easier to integrate with other queue systems

**Current Status:**

This enhancement was discussed in [GitHub Issue #35](https://github.com/bschmitt/laravel-amqp/issues/35). The maintainer noted that:
- It would be a significant refactoring
- Could conflict with future changes
- Would require careful consideration of breaking changes

**If You Need Multi-Transport Support:**

If you need to support multiple queue transports (not just RabbitMQ), consider:
1. Using [enqueue](https://github.com/php-enqueue/enqueue-dev) directly, which supports queue-interop interfaces
2. Creating an abstraction layer in your application that wraps this package
3. Contributing a queue-interop implementation as a pull request

**Reference:** This architectural decision was discussed in [GitHub Issue #35](https://github.com/bschmitt/laravel-amqp/issues/35). The package currently uses php-amqplib directly for RabbitMQ-specific functionality.

---

## Additional Questions

_Add your questions here or open an issue and we'll answer them!_

---

## Still Have Questions?

If you can't find what you're looking for here:

1. Check the [Configuration](Configuration) guide for setup issues
2. Review [Advanced Features](Advanced-Features) for specific features
3. See [Modules](Modules) for detailed implementation info
4. Check the [Testing](Testing) guide for test-related questions

---

**Note:** All features have been tested with RabbitMQ 3.x Management Docker image (`rabbitmq:3-management`). If you're using a different version, some features might behave differently.
