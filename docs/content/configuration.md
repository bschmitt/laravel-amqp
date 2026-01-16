# Configuration

## Basic Configuration

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

## Environment Variables

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

## Management API Configuration

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

## Multiple Environments

You can configure multiple environments:

```php
'properties' => [
    'production' => [
        'host' => 'prod-rabbitmq.example.com',
        // ...
    ],
    'staging' => [
        'host' => 'staging-rabbitmq.example.com',
        // ...
    ],
],
```

Then switch using:

```env
AMQP_ENV=staging
```
