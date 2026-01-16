# Getting Started with Laravel AMQP

## Quick Start

### 1. Installation

```bash
composer require bschmitt/laravel-amqp
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="Bschmitt\Amqp\Providers\AmqpServiceProvider"
```

### 3. Configure Environment

Add to your `.env`:

```env
AMQP_HOST=localhost
AMQP_PORT=5672
AMQP_USER=guest
AMQP_PASSWORD=guest
AMQP_VHOST=/
AMQP_EXCHANGE=amq.topic
AMQP_EXCHANGE_TYPE=topic
```

### 4. Basic Usage

#### Publish a Message

```php
use Bschmitt\Amqp\Facades\Amqp;

Amqp::publish('routing.key', 'Hello World');
```

#### Consume Messages

```php
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    echo $message->body;
    $resolver->acknowledge($message);
    $resolver->stopWhenProcessed();
});
```

That's it! You're ready to use Laravel AMQP.

## Next Steps

- [Configuration](#configuration)
- [Publishing Messages](#publishing)
- [Consuming Messages](#consuming)
- [RPC Pattern](#rpc)
