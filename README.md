# Laravel AMQP Package

A comprehensive AMQP wrapper for Laravel and Lumen to publish and consume messages, especially from RabbitMQ. This package provides full support for RabbitMQ features including RPC patterns, management operations, message properties, and more.

[![Build Status](https://travis-ci.org/bschmitt/laravel-amqp.svg?branch=master)](https://travis-ci.org/bschmitt/laravel-amqp)
[![Latest Stable Version](https://poser.pugx.org/bschmitt/laravel-amqp/v/stable.svg)](https://packagist.org/packages/bschmitt/laravel-amqp)
[![License](https://poser.pugx.org/bschmitt/laravel-amqp/license.svg)](https://packagist.org/packages/bschmitt/laravel-amqp)

## Features

### Core Features
- Advanced queue configuration
- Easy message publishing to queues
- Flexible queue consumption with useful options
- Support for all RabbitMQ exchange types (topic, direct, fanout, headers)
- Full AMQP message properties support

### Version 3.1.0 New Features
- **RPC Pattern Support** - Built-in request-response patterns with `rpc()` and `reply()` methods
- **Queue Management** - Programmatic control (purge, delete, unbind)
- **Management HTTP API** - Full integration with RabbitMQ Management API
- **Policy Management** - Create, update, and delete policies programmatically
- **Feature Flags** - Query RabbitMQ feature flags
- **Enhanced Message Properties** - Full support for priority, correlation_id, headers, etc.
- **Listen Method** - Auto-create queues and bind to multiple routing keys
- **Connection Configuration Helper** - Easy access to connection configs

### Advanced Features
- Publisher Confirms - Guaranteed message delivery
- Consumer Prefetch (QoS) - Rate limiting and flow control
- Queue Types - Classic, Quorum, and Stream queues
- Dead Letter Exchanges - Message routing for failed messages
- Message Priority - Priority-based message processing
- TTL Support - Message and queue expiration
- Lazy Queues - Disk-based message storage
- Alternate Exchange - Unroutable message handling

## Requirements

- PHP 8.1 or higher
- Laravel 8.x / 9.x / 10.x / 11.x or Lumen 8.x / 9.x / 10.x
- RabbitMQ 3.x (tested with `rabbitmq:3-management` Docker image)

## Installation

### Composer

```bash
composer require bschmitt/laravel-amqp
```

For Laravel 5.5+:
```json
"bschmitt/laravel-amqp": "^3.1"
```

For Laravel < 5.5:
```json
"bschmitt/laravel-amqp": "^2.0"
```

## Quick Start

### Publishing Messages

```php
use Bschmitt\Amqp\Facades\Amqp;

// Basic publish
Amqp::publish('routing-key', 'message');

// Publish with queue creation
Amqp::publish('routing-key', 'message', ['queue' => 'queue-name']);

// Publish with message properties
Amqp::publish('routing-key', 'message', [
    'priority' => 10,
    'correlation_id' => 'unique-id',
    'reply_to' => 'reply-queue',
    'application_headers' => [
        'X-Custom-Header' => 'value'
    ]
]);
```

### Consuming Messages

```php
// Consume and acknowledge
Amqp::consume('queue-name', function ($message, $resolver) {
    echo $message->body;
    $resolver->acknowledge($message);
    $resolver->stopWhenProcessed();
});

// Consume forever
Amqp::consume('queue-name', function ($message, $resolver) {
    processMessage($message->body);
    $resolver->acknowledge($message);
}, ['persistent' => true]);
```

### RPC Pattern

```php
// Client side - Make RPC call
$response = Amqp::rpc('rpc-queue', 'request-data', [], 30);

// Server side - Process and reply
Amqp::consume('rpc-queue', function ($message, $resolver) {
    $result = processRequest($message->body);
    $resolver->reply($message, $result);
    $resolver->acknowledge($message);
});
```

### Listen to Multiple Routing Keys

```php
Amqp::listen(['key1', 'key2', 'key3'], function ($message, $resolver) {
    processMessage($message->body);
    $resolver->acknowledge($message);
});
```

## Configuration

### Laravel

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Bschmitt\Amqp\Providers\AmqpServiceProvider"
```

Or manually copy `vendor/bschmitt/laravel-amqp/config/amqp.php` to `config/amqp.php`.

### Lumen

Create a `config` folder in your Lumen root and copy the configuration file:

```bash
mkdir config
cp vendor/bschmitt/laravel-amqp/config/amqp.php config/amqp.php
```

Register the service provider in `bootstrap/app.php`:

```php
$app->configure('amqp');
$app->register(Bschmitt\Amqp\Providers\LumenServiceProvider::class);

// For Lumen 5.2+, enable facades
$app->withFacades(true, [
    'Bschmitt\Amqp\Facades\Amqp' => 'Amqp',
]);
```

### Configuration Example

```php
return [
    'use' => 'production',

    'properties' => [
        'production' => [
            'host'                => env('AMQP_HOST', 'localhost'),
            'port'                => env('AMQP_PORT', 5672),
            'username'            => env('AMQP_USER', 'guest'),
            'password'            => env('AMQP_PASSWORD', 'guest'),
            'vhost'               => env('AMQP_VHOST', '/'),
            'exchange'            => env('AMQP_EXCHANGE', 'amq.topic'),
            'exchange_type'       => env('AMQP_EXCHANGE_TYPE', 'topic'),
            'consumer_tag'        => 'consumer',
            'ssl_options'         => [],
            'connect_options'     => [],
            'queue_properties'    => ['x-ha-policy' => ['S', 'all']],
            'exchange_properties' => [],
            'timeout'             => 0,
            
            // Management API (optional)
            'management_api_url' => env('AMQP_MANAGEMENT_URL', 'http://localhost:15672'),
            'management_api_user' => env('AMQP_MANAGEMENT_USER', 'guest'),
            'management_api_password' => env('AMQP_MANAGEMENT_PASSWORD', 'guest'),
        ],
    ],
];
```

## Documentation

### Comprehensive Guides

- **[User Manual](docs/USER_MANUAL.md)** - Complete usage guide
- **[Release Notes](RELEASE_NOTES.md)** - Version 3.1.0 changelog
- **[FAQ](docs/laravel-amqp.wiki/FAQ.md)** - Common questions and answers

### Wiki Documentation

- **[Getting Started](docs/laravel-amqp.wiki/Getting-Started.md)** - Installation and first steps
- **[Configuration](docs/laravel-amqp.wiki/Configuration.md)** - Configuration guide
- **[Publishing Messages](docs/laravel-amqp.wiki/Publishing-Messages.md)** - Publishing guide
- **[Consuming Messages](docs/laravel-amqp.wiki/Consuming-Messages.md)** - Consumption guide
- **[RPC Pattern](docs/laravel-amqp.wiki/RPC-Pattern.md)** - Request-response patterns
- **[Queue Management](docs/laravel-amqp.wiki/Queue-Management.md)** - Queue operations
- **[Management API](docs/laravel-amqp.wiki/Management-API.md)** - HTTP API integration
- **[Message Properties](docs/laravel-amqp.wiki/Message-Properties.md)** - Message properties
- **[Advanced Features](docs/laravel-amqp.wiki/Advanced-Features.md)** - Advanced usage
- **[Architecture](docs/laravel-amqp.wiki/Architecture.md)** - Package architecture
- **[Testing](docs/laravel-amqp.wiki/Testing.md)** - Testing guide

### Module Documentation

See [docs/modules/](docs/modules/) for detailed module documentation:
- RPC Module
- Management Operations
- Management API
- Message Properties
- Consumer Prefetch
- And more...

## Examples

### Fanout Exchange

```php
// Publishing
Amqp::publish('', 'message', [
    'exchange_type' => 'fanout',
    'exchange' => 'amq.fanout',
]);

// Consuming
Amqp::consume('', function ($message, $resolver) {
    echo $message->body;
    $resolver->acknowledge($message);
}, [
    'routing' => '',
    'exchange' => 'amq.fanout',
    'exchange_type' => 'fanout',
    'queue_force_declare' => true,
    'queue_exclusive' => true,
    'persistent' => true
]);
```

### Queue Management

```php
// Purge queue
Amqp::queuePurge('my-queue', ['queue' => 'my-queue']);

// Delete queue
Amqp::queueDelete('my-queue', ['queue' => 'my-queue']);

// Get queue statistics
$stats = Amqp::getQueueStats('my-queue', '/');
```

### Management API

```php
// Get queue statistics
$stats = Amqp::getQueueStats('my-queue', '/');

// List connections
$connections = Amqp::getConnections();

// Create policy
Amqp::createPolicy('my-policy', [
    'pattern' => '^my-queue$',
    'definition' => ['max-length' => 1000]
], '/');
```

## Testing

The package includes comprehensive test coverage:

```bash
# Run all tests
php vendor/bin/phpunit

# Run unit tests only
php vendor/bin/phpunit test/Unit/

# Run integration tests only
php vendor/bin/phpunit test/Integration/
```

**Test Requirements:**
- RabbitMQ server running (for integration tests)
- Docker: `docker run -d --name rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:3-management`

See [Testing Guide](docs/laravel-amqp.wiki/Testing.md) for more information.

## Version 3.1.0 Highlights

### New Methods

**RPC:**
- `Amqp::rpc($routingKey, $request, $properties, $timeout)` - Make RPC calls
- `Consumer::reply($message, $response, $properties)` - Send RPC responses
- `Amqp::listen($routingKeys, $callback, $properties)` - Auto-create queues with multiple bindings

**Management:**
- `Amqp::queuePurge($queue, $properties)` - Purge queue
- `Amqp::queueDelete($queue, $ifUnused, $ifEmpty, $properties)` - Delete queue
- `Amqp::queueUnbind(...)` - Unbind queue
- `Amqp::exchangeDelete(...)` - Delete exchange
- `Amqp::exchangeUnbind(...)` - Unbind exchange

**Management API:**
- `Amqp::getQueueStats($queue, $vhost, $properties)` - Queue statistics
- `Amqp::getConnections($connectionName, $properties)` - List connections
- `Amqp::getChannels($channelName, $properties)` - List channels
- `Amqp::getNodes($nodeName, $properties)` - Cluster nodes
- `Amqp::getPolicies($properties)` - List policies
- `Amqp::createPolicy(...)` - Create policy
- `Amqp::updatePolicy(...)` - Update policy
- `Amqp::deletePolicy(...)` - Delete policy
- `Amqp::listFeatureFlags($properties)` - List feature flags
- `Amqp::getFeatureFlag($name, $properties)` - Get feature flag

**Helpers:**
- `Amqp::getConnectionConfig($connectionName)` - Get connection config

## Backward Compatibility

Version 3.1.0 is fully backward compatible with previous versions. All existing code will continue to work without modifications.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Credits

- Some concepts were used from [mookofe/tail](https://github.com/mookofe/tail)
- Built and tested with `rabbitmq:3-management` Docker image

## License

This package is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).

## Support

For issues, questions, or contributions:
- GitHub Issues: [https://github.com/bschmitt/laravel-amqp/issues](https://github.com/bschmitt/laravel-amqp/issues)
- Documentation: See `docs/` directory
- FAQ: [docs/laravel-amqp.wiki/FAQ.md](docs/laravel-amqp.wiki/FAQ.md)

---

**Version:** 3.1.0  
**Status:** Production Ready
