# Installation

## Requirements

- PHP 7.3+ or PHP 8.0+
- Laravel 6.20+ / Lumen 6.20+
- RabbitMQ 3.x server
- php-amqplib/php-amqplib ^3.0

## Composer Installation

```bash
composer require bschmitt/laravel-amqp
```

## Laravel Setup

The package will auto-register its service provider. Publish the configuration file:

```bash
php artisan vendor:publish --provider="Bschmitt\Amqp\Providers\AmqpServiceProvider"
```

## Lumen Setup

For Lumen, register the service provider in `bootstrap/app.php`:

```php
$app->register(Bschmitt\Amqp\Providers\LumenServiceProvider::class);
```

Then copy the config file manually:

```bash
cp vendor/bschmitt/laravel-amqp/config/amqp.php config/amqp.php
```

## Verify Installation

Test your connection:

```php
use Bschmitt\Amqp\Facades\Amqp;

try {
    Amqp::publish('test', 'test');
    echo "Connection successful";
} catch (\Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}
```
