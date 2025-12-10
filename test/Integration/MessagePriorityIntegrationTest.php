<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Consumer;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RabbitMQ Message Priority feature
 * Requires RabbitMQ running on localhost:5672
 * 
 * To run:
 * 1. Start RabbitMQ: docker-compose up -d rabbit
 * 2. Run: php vendor/bin/phpunit test/MessagePriorityIntegrationTest.php
 * 
 * Reference: https://www.rabbitmq.com/docs/priority
 */
class MessagePriorityIntegrationTest extends TestCase
{
    private $configRepository;
    private $testQueueName;
    private $testExchange;
    private $testRoutingKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Use fixed queue name for tests
        $this->testQueueName = 'test-priority';
        $this->testExchange = 'test-exchange-priority';
        $this->testRoutingKey = 'test.routing.key';
    }

    /**
     * Create a test configuration with custom queue properties
     */
    private function createConfig(array $queueProperties = []): Repository
    {
        $defaultProperties = [
            'host' => env('AMQP_HOST', 'localhost'),
            'port' => (int) env('AMQP_PORT', 5672),
            'username' => env('AMQP_USER', 'guest'),
            'password' => env('AMQP_PASSWORD', 'guest'),
            'vhost' => env('AMQP_VHOST', '/'),
            'connect_options' => [],
            'ssl_options' => [],

            'exchange' => $this->testExchange,
            'exchange_type' => 'topic',
            'exchange_passive' => false,
            'exchange_durable' => true,
            'exchange_auto_delete' => false,
            'exchange_internal' => false,
            'exchange_nowait' => false,
            'exchange_properties' => [],

            'queue' => $this->testQueueName,
            'queue_force_declare' => true,
            'queue_passive' => false,
            'queue_durable' => false,
            'queue_exclusive' => false,
            'queue_auto_delete' => true,
            'queue_nowait' => false,
            'queue_properties' => $queueProperties,

            'routing' => $this->testRoutingKey,
            'consumer_tag' => 'test-consumer',
            'consumer_no_local' => false,
            'consumer_no_ack' => false,
            'consumer_exclusive' => false,
            'consumer_nowait' => false,
            'consumer_properties' => [],
            'timeout' => 2,
            'persistent' => false,
            'publish_timeout' => 0,
            'qos' => false,
            'qos_prefetch_size' => 0,
            'qos_prefetch_count' => 1,
            'qos_a_global' => false
        ];

        $config = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => $defaultProperties
                ]
            ]
        ];

        return new Repository($config);
    }

    /**
     * Test that messages with higher priority are consumed first
     * 
     * According to RabbitMQ docs:
     * - Messages with higher priority are delivered before lower priority
     * - Priority must be <= queue's max-priority
     * - Messages without priority are treated as priority 0
     */
    public function testHighPriorityMessagesConsumedFirst()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Create queue with max priority 10
        $config = $this->createConfig([
            'x-max-priority' => 10
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Publish messages in order: low, high, medium
        // Expected consumption order: high, medium, low
        $messages = [
            ['body' => 'low-priority', 'priority' => 1],
            ['body' => 'high-priority', 'priority' => 10],
            ['body' => 'medium-priority', 'priority' => 5],
        ];

        foreach ($messages as $msg) {
            $message = new \Bschmitt\Amqp\Models\Message($msg['body'], [
                'content_type' => 'text/plain',
                'delivery_mode' => 2,
                'priority' => $msg['priority']
            ]);
            $publisher->publish($this->testRoutingKey, $message);
            usleep(50000); // Small delay
        }

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume messages - should be in priority order
        $consumer = new Consumer($config);
        $consumer->setup();

        $consumedMessages = [];
        $messageCount = 0;
        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) use (&$consumedMessages, &$messageCount) {
                $consumedMessages[] = $message->body;
                $messageCount++;
                $resolver->acknowledge($message);
                if ($messageCount >= 3) {
                    $resolver->stopWhenProcessed();
                }
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        // Verify consumption order: high, medium, low
        $this->assertCount(3, $consumedMessages, 'All messages should be consumed');
        $this->assertEquals('high-priority', $consumedMessages[0], 'High priority message should be consumed first');
        $this->assertEquals('medium-priority', $consumedMessages[1], 'Medium priority message should be consumed second');
        $this->assertEquals('low-priority', $consumedMessages[2], 'Low priority message should be consumed last');
    }

    /**
     * Test that messages without priority are treated as priority 0
     */
    public function testMessagesWithoutPriorityTreatedAsZero()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Create queue with max priority 10
        $config = $this->createConfig([
            'x-max-priority' => 10
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Publish: no priority (treated as 0), then priority 5
        // Expected order: priority 5, then no priority
        $message1 = new \Bschmitt\Amqp\Models\Message('no-priority', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
            // No priority property
        ]);
        $publisher->publish($this->testRoutingKey, $message1);

        $message2 = new \Bschmitt\Amqp\Models\Message('with-priority-5', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2,
            'priority' => 5
        ]);
        $publisher->publish($this->testRoutingKey, $message2);

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume
        $consumer = new Consumer($config);
        $consumer->setup();

        $consumedMessages = [];
        $messageCount = 0;
        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) use (&$consumedMessages, &$messageCount) {
                $consumedMessages[] = $message->body;
                $messageCount++;
                $resolver->acknowledge($message);
                if ($messageCount >= 2) {
                    $resolver->stopWhenProcessed();
                }
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        // Priority 5 should be consumed before no-priority (0)
        $this->assertCount(2, $consumedMessages);
        $this->assertEquals('with-priority-5', $consumedMessages[0], 'Priority 5 should be consumed first');
        $this->assertEquals('no-priority', $consumedMessages[1], 'No priority (0) should be consumed second');
    }

    /**
     * Test that messages with priority exceeding max-priority are capped
     */
    public function testPriorityExceedingMaxIsCapped()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Create queue with max priority 5
        $config = $this->createConfig([
            'x-max-priority' => 5
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Publish message with priority 10 (exceeds max 5)
        // Should be treated as priority 5
        $message1 = new \Bschmitt\Amqp\Models\Message('priority-10-capped', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2,
            'priority' => 10  // Exceeds max-priority of 5
        ]);
        $publisher->publish($this->testRoutingKey, $message1);

        // Publish message with priority 5 (at max)
        $message2 = new \Bschmitt\Amqp\Models\Message('priority-5-max', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2,
            'priority' => 5
        ]);
        $publisher->publish($this->testRoutingKey, $message2);

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume - both should be treated as priority 5, so order may vary
        $consumer = new Consumer($config);
        $consumer->setup();

        $consumedMessages = [];
        $messageCount = 0;
        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) use (&$consumedMessages, &$messageCount) {
                $consumedMessages[] = $message->body;
                $messageCount++;
                $resolver->acknowledge($message);
                if ($messageCount >= 2) {
                    $resolver->stopWhenProcessed();
                }
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        // Both messages should be consumed (order may vary if same priority)
        $this->assertCount(2, $consumedMessages, 'Both messages should be consumed');
    }

    /**
     * Test priority with multiple priority levels
     */
    public function testMultiplePriorityLevels()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Create queue with max priority 10
        $config = $this->createConfig([
            'x-max-priority' => 10
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Publish messages with various priorities
        $messages = [
            ['body' => 'priority-1', 'priority' => 1],
            ['body' => 'priority-8', 'priority' => 8],
            ['body' => 'priority-3', 'priority' => 3],
            ['body' => 'priority-10', 'priority' => 10],
            ['body' => 'priority-5', 'priority' => 5],
        ];

        foreach ($messages as $msg) {
            $message = new \Bschmitt\Amqp\Models\Message($msg['body'], [
                'content_type' => 'text/plain',
                'delivery_mode' => 2,
                'priority' => $msg['priority']
            ]);
            $publisher->publish($this->testRoutingKey, $message);
            usleep(50000);
        }

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume - should be in descending priority order
        $consumer = new Consumer($config);
        $consumer->setup();

        $consumedMessages = [];
        $messageCount = 0;
        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) use (&$consumedMessages, &$messageCount) {
                $consumedMessages[] = $message->body;
                $messageCount++;
                $resolver->acknowledge($message);
                if ($messageCount >= 5) {
                    $resolver->stopWhenProcessed();
                }
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        // Verify order: 10, 8, 5, 3, 1
        $this->assertCount(5, $consumedMessages);
        $this->assertEquals('priority-10', $consumedMessages[0], 'Highest priority first');
        $this->assertEquals('priority-8', $consumedMessages[1], 'Second highest');
        $this->assertEquals('priority-5', $consumedMessages[2], 'Third highest');
        $this->assertEquals('priority-3', $consumedMessages[3], 'Fourth highest');
        $this->assertEquals('priority-1', $consumedMessages[4], 'Lowest priority last');
    }

    /**
     * Check if RabbitMQ is available
     */
    private function isRabbitMQAvailable(): bool
    {
        $host = env('AMQP_HOST', 'localhost');
        $port = (int) env('AMQP_PORT', 5672);

        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);
            return true;
        }
        return false;
    }
}

