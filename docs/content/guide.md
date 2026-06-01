# Laravel AMQP Documentation

Welcome to the Laravel AMQP package documentation. This guide will help you get started with RabbitMQ in your Laravel application.

## Quick Navigation

### Getting Started

- [Quick Start](#getting-started) - Get up and running quickly
- [Installation](#installation) - Detailed installation guide
- [Configuration](#configuration) - Configure your connection

### Core Features

- [Publishing Messages](#publishing) - Send messages to queues
- [Consuming Messages](#consuming) - Process messages from queues
- [Laravel Queue Driver](#queue-driver) - Native `queue:work` integration
- [Artisan Commands](#artisan-commands) - `amqp:work`, `amqp:consume`, `amqp:listen`, `amqp:publish`, `amqp:purge`
- [RPC Pattern](#rpc) - Request-response communication

### Management

- [Queue Management](#queue-management) - Manage queues and exchanges
- [Management API](#management-api) - Use RabbitMQ Management API

### Advanced

- [Message Properties](#message-properties) - Work with message metadata
- [Advanced Features](#advanced) - Publisher confirms, QoS, queue types, retry/DLQ abstractions
- [Delayed Messaging](#delayed-messaging) - `publishLater()` and publisher-side backoff
- [Typed Messaging](#typed-messaging) - DTO contracts and pluggable serializers
- [Schema Validation](#schema-validation) - Zero-dependency JSON schema enforcement
- [Best Practices](#best-practices) - Production-ready patterns

### Reference

- [FAQ](#faq) - Common questions
- [Troubleshooting](#troubleshooting) - Solve common issues

## Package Features

- Native Laravel queue driver (`queue:work amqp`)
- Artisan commands: `amqp:work`, `amqp:consume`, `amqp:listen`, `amqp:publish`, `amqp:purge`
- Simple API for publishing and consuming
- RPC pattern support
- Queue management operations
- RabbitMQ Management API integration
- Full message properties support
- Publisher confirms
- Consumer prefetch (QoS)
- Multiple queue types (classic, quorum, stream)
- Dead letter exchanges
- Advanced retry & dead-letter abstractions (`RetryPolicy`, `DeadLetterTopology`, `RetryHandler`)
- Delayed publishing via TTL+DLX or the `rabbitmq-delayed-message-exchange` plugin
- Publisher-side backoff (`PublishBackoff`)
- Typed message contracts & DTO serialization (`TypedMessage`, `publishTyped()`, `consumeTyped()`)
- JSON Schema validation for inbound and outbound payloads
- Message priority
- TTL support

## Quick Example

```php
use Bschmitt\Amqp\Facades\Amqp;

// Publish
Amqp::publish('routing.key', 'Hello World');

// Consume
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    echo $message->body;
    $resolver->acknowledge($message);
});
```

## Support

- **GitHub:** [bschmitt/laravel-amqp](https://github.com/bschmitt/laravel-amqp)
- **Issues:** [GitHub Issues](https://github.com/bschmitt/laravel-amqp/issues)
- **Packagist:** [bschmitt/laravel-amqp](https://packagist.org/packages/bschmitt/laravel-amqp)
