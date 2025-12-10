# Test Suite Documentation

This directory contains the comprehensive test suite for the Laravel AMQP package.

## Test Structure

The test suite is organized into modular folders for better maintainability:

```
test/
├── Unit/                    # Unit tests (with mocks)
│   ├── BackwardCompatibilityTest.php
│   ├── DeadLetterExchangeTest.php
│   ├── LazyQueueTest.php
│   ├── MessagePriorityTest.php
│   ├── PublisherTest.php
│   ├── QueueMaxLengthTest.php
│   ├── QueueTTLTest.php
│   ├── QueueTypeTest.php
│   └── RequestTest.php
│
├── Integration/             # Integration tests (real RabbitMQ)
│   ├── ConsumeAllMessagesTest.php
│   ├── ConsumeExistingQueueMessagesTest.php
│   ├── ConsumerVerificationTest.php
│   ├── DeadLetterExchangeIntegrationTest.php
│   ├── FullIntegrationTest.php
│   ├── LazyQueueIntegrationTest.php
│   ├── MessagePriorityIntegrationTest.php
│   ├── PublishConsumeVerificationTest.php
│   ├── QueueMaxLengthCompleteTest.php
│   ├── QueueMaxLengthIntegrationTest.php
│   ├── QueueTTLIntegrationTest.php
│   └── QueueTypeIntegrationTest.php
│
├── Support/                 # Base classes and helpers
│   ├── BaseTestCase.php           # Base class for unit tests
│   ├── IntegrationTestBase.php   # Base class for integration tests
│   └── ConsumeQueueHelper.php    # Helper for consuming messages
│
├── README.md                # This file
└── README-INTEGRATION.md   # Integration test guide
```

## Running Tests

### Run All Tests

```bash
php vendor/bin/phpunit test/
```

### Run Unit Tests Only

```bash
php vendor/bin/phpunit test/Unit/
```

### Run Integration Tests Only

```bash
php vendor/bin/phpunit test/Integration/
```

### Run Specific Test Suite

```bash
# Queue Type tests
php vendor/bin/phpunit test/Unit/QueueTypeTest.php
php vendor/bin/phpunit test/Integration/QueueTypeIntegrationTest.php

# Lazy Queue tests
php vendor/bin/phpunit test/Unit/LazyQueueTest.php
php vendor/bin/phpunit test/Integration/LazyQueueIntegrationTest.php
```

## Test Categories

### Unit Tests (`test/Unit/`)

Unit tests use mocks and don't require a real RabbitMQ instance. They test:
- Configuration handling
- Property validation
- Class behavior with mocked dependencies

**Key Features:**
- Fast execution
- No external dependencies
- Isolated testing
- Uses Mockery for mocking

### Integration Tests (`test/Integration/`)

Integration tests require a real RabbitMQ instance. They test:
- Real queue/exchange operations
- End-to-end message flow
- RabbitMQ feature compatibility
- Actual message publishing and consuming

**Requirements:**
- RabbitMQ server running
- Connection credentials in `.env` file
- See [README-INTEGRATION.md](./README-INTEGRATION.md) for setup

## Test Coverage

### Feature Coverage

| Feature | Unit Tests | Integration Tests |
|---------|-----------|-------------------|
| Maximum Queue Length | ✅ | ✅ |
| Message TTL | ✅ | ✅ |
| Queue Expires | ✅ | ✅ |
| Dead Letter Exchange | ✅ | ✅ |
| Message Priority | ✅ | ✅ |
| Lazy Queues | ✅ | ✅ |
| Queue Types | ✅ | ✅ |
| Publisher | ✅ | ✅ |
| Consumer | ✅ | ✅ |
| Request | ✅ | - |
| Backward Compatibility | ✅ | - |

## Base Classes

### BaseTestCase (`test/Support/BaseTestCase.php`)

Base class for all unit tests. Provides:
- Mock setup utilities
- Configuration helpers
- Protected property accessors

### IntegrationTestBase (`test/Support/IntegrationTestBase.php`)

Base class for all integration tests. Provides:
- RabbitMQ connection setup
- Queue/exchange management
- Environment variable loading
- Connection availability checks

## Helper Classes

### ConsumeQueueHelper (`test/Support/ConsumeQueueHelper.php`)

Utility class for consuming messages in tests. Provides:
- Message consumption helpers
- Queue status checking
- Message counting utilities

## Writing New Tests

### Unit Test Template

```php
<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Request;
use \Mockery;

class MyFeatureTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup mocks
    }

    public function testMyFeature()
    {
        // Test implementation
    }
}
```

### Integration Test Template

```php
<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Consumer;

class MyFeatureIntegrationTest extends IntegrationTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup test queue/exchange
    }

    public function testMyFeature()
    {
        // Integration test implementation
    }
}
```

## Best Practices

1. **Use Fixed Queue Names**: Don't use `uniqid()` - use fixed names to avoid cluttering RabbitMQ
2. **Clean Up**: Delete queues in `tearDown()` to ensure clean state
3. **Skip When Unavailable**: Use `markTestSkipped()` if RabbitMQ is not available
4. **Isolate Tests**: Each test should be independent and not rely on others
5. **Use Appropriate Base Class**: Extend `BaseTestCase` for unit tests, `IntegrationTestBase` for integration tests

## Configuration

Tests use configuration from:
- `config/amqp.php` - Default configuration
- `.env` file - Environment-specific settings (for integration tests)

Required `.env` variables for integration tests:
```
AMQP_HOST=localhost
AMQP_PORT=5672
AMQP_USER=guest
AMQP_PASSWORD=guest
AMQP_VHOST=/
```

## Troubleshooting

### Tests Fail with "RabbitMQ is not available"
- Ensure RabbitMQ is running
- Check connection credentials in `.env`
- Verify network connectivity

### PRECONDITION_FAILED Errors
- Delete existing queues in RabbitMQ Web UI
- Ensure queue properties match between tests
- Use `deleteQueue()` helper in `setUp()` to clean state

### Tests Timeout
- Increase timeout in test configuration
- Check RabbitMQ server performance
- Verify no blocking operations

## See Also

- [Integration Test Guide](./README-INTEGRATION.md) - Detailed integration testing guide
- [Main Package README](../README.md) - Package overview
