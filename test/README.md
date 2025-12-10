# Testing Queue Max Length Feature

This directory contains tests for the queue max length feature (fix for issue #120).

## RabbitMQ Documentation Reference

This feature implements RabbitMQ's queue length limit functionality. For detailed documentation, see:
- [RabbitMQ Queue Length Documentation](https://www.rabbitmq.com/maxlength.html)

Key points:
- `x-max-length`: Maximum number of messages (only ready messages count)
- `x-max-length-bytes`: Maximum size in bytes (optional)
- `x-overflow`: Overflow behavior - `drop-head` (default), `reject-publish`, or `reject-publish-dlx`
- Default behavior: When limit is reached, oldest messages are dropped from the front (drop-head)

## Test Files

- `QueueMaxLengthTest.php` - Unit tests using mocks
- `QueueMaxLengthIntegrationTest.php` - Integration tests requiring RabbitMQ

## Running Tests

### Integration Tests (Real RabbitMQ - No Mocks)

To run tests with real RabbitMQ credentials and no mocks:

1. **Ensure RabbitMQ is running:**
   ```bash
   # Using Docker
   docker run -d --name rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:3-management
   
   # Or use docker-compose if available
   docker-compose up -d rabbit
   ```

2. **Set credentials in `.env` file (in project root):**
   ```env
   AMQP_HOST=localhost
   AMQP_PORT=5672
   AMQP_USER=guest
   AMQP_PASSWORD=guest
   AMQP_VHOST=/
   ```

3. **Run integration tests:**
   ```bash
   # Linux/Mac
   ./run-integration-tests.sh
   
   # Windows
   run-integration-tests.bat
   
   # Or directly with PHPUnit
   php vendor/bin/phpunit test/FullIntegrationTest.php test/QueueMaxLengthIntegrationTest.php
   ```

**Available Integration Test Suites:**
- `FullIntegrationTest.php` - Comprehensive integration tests covering:
  - Basic publish/consume
  - Batch publishing
  - Message rejection (with/without requeue)
  - Amqp facade methods
  - Queue message count
  - Mandatory publishing
  - QoS configuration
  - Multiple routing keys
- `QueueMaxLengthIntegrationTest.php` - Tests for queue max length feature

### Prerequisites

1. **Start RabbitMQ using Docker:**
   ```bash
   docker-compose up -d rabbit
   ```

2. **Verify RabbitMQ is running:**
   ```bash
   docker ps | grep rabbit
   ```

### Running Unit Tests (No RabbitMQ Required)

```bash
cd packages/zfhassaan/laravel-amqp
php vendor/bin/phpunit test/QueueMaxLengthTest.php
```

### Running Integration Tests (Requires RabbitMQ)

```bash
cd packages/zfhassaan/laravel-amqp
php vendor/bin/phpunit test/QueueMaxLengthIntegrationTest.php
```

### Running All Tests

```bash
cd packages/zfhassaan/laravel-amqp
php vendor/bin/phpunit
```

## Environment Variables

You can customize RabbitMQ connection settings using environment variables:

```bash
export AMQP_HOST=localhost
export AMQP_PORT=5672
export AMQP_USER=guest
export AMQP_PASSWORD=guest
export AMQP_VHOST=/
```

## What the Tests Verify

1. **Unit Tests:**
   - Queue properties include `x-max-length` when configured
   - Default config includes `x-max-length = 1`
   - Custom max length values can be set

2. **Integration Tests:**
   - Queue with `x-max-length = 1` only keeps the latest message
   - Older messages are automatically dropped when queue reaches max length
   - Queue respects max length when messages are consumed

## Troubleshooting

If integration tests are skipped:
- Ensure RabbitMQ is running: `docker ps | grep rabbit`
- Check connection: `telnet localhost 5672`
- Verify credentials match your RabbitMQ setup

