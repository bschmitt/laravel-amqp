# Integration Tests - Real RabbitMQ Testing

## Overview

These integration tests use **real RabbitMQ connections** with **no mocks**. They verify that the entire AMQP functionality works correctly with an actual RabbitMQ server.

## Quick Start

1. **Start RabbitMQ:**
   ```bash
   docker run -d --name rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:3-management
   ```

2. **Verify credentials in `.env` (project root):**
   ```env
   AMQP_HOST=localhost
   AMQP_PORT=5672
   AMQP_USER=guest
   AMQP_PASSWORD=guest
   AMQP_VHOST=/
   ```

3. **Run tests:**
   ```bash
   php vendor/bin/phpunit test/FullIntegrationTest.php
   ```

## Test Suites

### FullIntegrationTest.php
Comprehensive integration tests covering all functionality:

- **testBasicPublish** - Basic message publishing
- **testBasicConsume** - Basic message consumption
- **testBatchPublish** - Batch message publishing
- **testMessageRejectionWithRequeue** - Reject and requeue messages
- **testMessageRejectionWithoutRequeue** - Reject without requeue
- **testAmqpFacadePublish** - Using Amqp facade to publish
- **testAmqpFacadeConsume** - Using Amqp facade to consume
- **testQueueMessageCount** - Check queue message count
- **testMandatoryPublish** - Mandatory publishing
- **testQoSConfiguration** - Quality of Service settings
- **testMultipleRoutingKeys** - Multiple routing key bindings

### ConsumerVerificationTest.php
Debug and verification tests with detailed output:

- **testPublishAndConsumeImmediately** - Verify immediate consumption
- **testConsumeWithTimeout** - Test with timeout settings
- **testQueueStatusCheck** - Check queue status and message count
- **testConsumeFromEmptyQueue** - Handle empty queue gracefully

### QueueMaxLengthIntegrationTest.php
Tests for queue max length feature:

- **testQueueMaxLengthKeepsOnlyLatestMessage** - Max length = 1 behavior
- **testQueueMaxLengthWithConsumption** - Max length with consumption

## Troubleshooting

### Tests are Skipped
If tests show as "Skipped", RabbitMQ is not available:
- Check if RabbitMQ is running: `docker ps | grep rabbitmq`
- Verify connection: `telnet localhost 5672`
- Check credentials in `.env`

### Messages Not Consuming
If messages are published but not consumed:

1. **Check queue message count:**
   ```php
   $consumer->setup();
   $count = $consumer->getQueueMessageCount();
   echo "Queue has {$count} messages\n";
   ```

2. **Verify consumer configuration:**
   - `persistent` should be `true` for tests
   - `timeout` should be set (e.g., 5 seconds)
   - Queue should be properly bound to exchange

3. **Use ConsumerVerificationTest:**
   ```bash
   php vendor/bin/phpunit test/ConsumerVerificationTest.php
   ```
   This test provides detailed output showing each step.

### Connection Timeout
If you see "connection timed out":
- RabbitMQ might be slow to respond
- Increase timeout in config: `'timeout' => 10`
- Check RabbitMQ logs: `docker logs rabbitmq`

## Configuration

The integration tests use these default settings:

```php
'persistent' => true,        // Keep consumer running
'timeout' => 5,              // 5 second timeout
'queue_auto_delete' => true, // Auto-cleanup
'queue_durable' => false,    // Non-durable for tests
```

## Running Specific Tests

```bash
# Run single test
php vendor/bin/phpunit test/FullIntegrationTest.php --filter testBasicConsume

# Run with verbose output
php vendor/bin/phpunit test/ConsumerVerificationTest.php --testdox

# Run all integration tests
php vendor/bin/phpunit test/FullIntegrationTest.php test/ConsumerVerificationTest.php test/QueueMaxLengthIntegrationTest.php
```

## Expected Output

When tests pass, you should see:
```
OK, but there were issues!
Tests: 11, Assertions: 30, Deprecations: 2, PHPUnit Deprecations: 1.
```

The "issues" are just deprecation warnings, not failures.

## Debug Mode

For detailed debugging, use `ConsumerVerificationTest` which outputs:
- When messages are published
- Queue message counts
- When callbacks execute
- Message content verification

Example output:
```
[TEST] Publishing message: immediate-consume-test
[TEST] Publish result: SUCCESS
[TEST] Queue message count: 1
[TEST] Starting consumer...
[TEST] Callback executed! Message received: immediate-consume-test
[TEST] Message acknowledged
[TEST] All assertions passed!
```



