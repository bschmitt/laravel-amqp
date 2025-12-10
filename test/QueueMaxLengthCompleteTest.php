<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Consumer;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Complete integration tests for RabbitMQ Queue Length Limits
 * 
 * Tests all features from: https://www.rabbitmq.com/docs/maxlength
 * 
 * Features tested:
 * 1. x-max-length: Maximum number of messages
 * 2. x-max-length-bytes: Maximum size in bytes
 * 3. x-overflow: Overflow behavior (drop-head, reject-publish, reject-publish-dlx)
 * 
 * To run:
 * 1. Start RabbitMQ: docker-compose up -d rabbit
 * 2. Run: php vendor/bin/phpunit test/QueueMaxLengthCompleteTest.php
 */
class QueueMaxLengthCompleteTest extends TestCase
{
    private $configRepository;
    private $testQueueName;
    private $testExchange;
    private $testRoutingKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Generate unique queue name for each test
        $this->testQueueName = 'test-maxlength-' . uniqid();
        $this->testExchange = 'test-exchange-' . uniqid();
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
     * Test x-max-length-bytes: Maximum size in bytes
     * 
     * According to RabbitMQ docs:
     * - x-max-length-bytes: Maximum total size of all message bodies in bytes
     * - Only ready messages count (unacknowledged don't count)
     * - Default overflow: drop-head
     */
    public function testMaxLengthBytes()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Set max length to 100 bytes (small limit for testing)
        $config = $this->createConfig([
            'x-max-length-bytes' => 100
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Publish messages that exceed 100 bytes total
        // Each message is ~20 bytes, so 6 messages = ~120 bytes
        // Only first 5 should remain (100 bytes limit)
        $messages = [];
        for ($i = 1; $i <= 6; $i++) {
            $messageText = "Message-$i-" . str_repeat('x', 10); // ~20 bytes each
            $messages[] = $messageText;
            $message = new \Bschmitt\Amqp\Models\Message($messageText, [
                'content_type' => 'text/plain',
                'delivery_mode' => 2
            ]);
            $publisher->publish($this->testRoutingKey, $message);
            usleep(50000); // Small delay
        }

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume and verify only messages within byte limit remain
        $consumer = new Consumer($config);
        $consumer->setup();

        $consumedMessages = [];
        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) use (&$consumedMessages) {
                $consumedMessages[] = $message->body;
                $resolver->acknowledge($message);
                $resolver->stopWhenProcessed();
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        // Should have messages, but not all 6 (some dropped due to byte limit)
        $this->assertGreaterThan(0, count($consumedMessages), 'Should have some messages');
        $this->assertLessThan(6, count($consumedMessages), 'Some messages should be dropped due to byte limit');
    }

    /**
     * Test x-overflow: drop-head (default behavior)
     * 
     * According to RabbitMQ docs:
     * - drop-head: Drops oldest messages from front when limit is reached
     * - This is the default behavior
     */
    public function testOverflowDropHead()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        $config = $this->createConfig([
            'x-max-length' => 2,
            'x-overflow' => 'drop-head'
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Publish 4 messages, only last 2 should remain
        $messages = ['msg-1', 'msg-2', 'msg-3', 'msg-4'];
        foreach ($messages as $msg) {
            $message = new \Bschmitt\Amqp\Models\Message($msg, [
                'content_type' => 'text/plain',
                'delivery_mode' => 2
            ]);
            $publisher->publish($this->testRoutingKey, $message);
            usleep(50000);
        }

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume - should get last 2 messages
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

        $this->assertCount(2, $consumedMessages, 'Should have exactly 2 messages');
        $this->assertEquals('msg-3', $consumedMessages[0], 'First should be msg-3');
        $this->assertEquals('msg-4', $consumedMessages[1], 'Second should be msg-4');
    }

    /**
     * Test x-overflow: reject-publish
     * 
     * According to RabbitMQ docs:
     * - reject-publish: Rejects new publishes with basic.nack when queue is full
     * - Messages are not enqueued, publisher receives nack
     * - Requires publisher confirms to be enabled
     * 
     * Note: This test verifies that reject-publish works, but the actual nack
     * handling requires careful timing. We verify by checking queue message count.
     */
    public function testOverflowRejectPublish()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Use unique queue name to avoid conflicts
        $uniqueQueueName = 'test-queue-reject-publish-' . uniqid();
        $config = $this->createConfig([
            'x-max-length' => 2,
            'x-overflow' => 'reject-publish'
        ]);
        
        // Override queue name
        $configData = $config->get('amqp');
        $configData['properties']['test']['queue'] = $uniqueQueueName;
        $configData['properties']['test']['queue_force_declare'] = true;
        $config = new \Illuminate\Config\Repository(['amqp' => $configData]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Fill queue to max (2 messages) - publish without mandatory to avoid blocking
        $message1 = new \Bschmitt\Amqp\Models\Message('msg-1', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
        ]);
        $message2 = new \Bschmitt\Amqp\Models\Message('msg-2', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
        ]);

        // Publish first two messages (should succeed)
        $result1 = $publisher->publish($this->testRoutingKey, $message1, false);
        usleep(100000); // Small delay
        $result2 = $publisher->publish($this->testRoutingKey, $message2, false);
        usleep(200000); // Wait for messages to be in queue

        // Try to publish 3rd message - should be rejected (but we can't easily detect nack without confirms)
        $message3 = new \Bschmitt\Amqp\Models\Message('msg-3', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
        ]);
        $result3 = $publisher->publish($this->testRoutingKey, $message3, false);

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Wait a bit for any rejected messages to be processed
        usleep(300000);

        // Consume - should only have 2 messages (3rd was rejected)
        $consumer = new Consumer($config);
        $consumer->setup();

        $consumedMessages = [];
        $messageCount = 0;
        try {
            $consumer->consume($uniqueQueueName, function ($message, $resolver) use (&$consumedMessages, &$messageCount) {
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

        // Should only have 2 messages (queue was full, 3rd rejected)
        $this->assertCount(2, $consumedMessages, 'Should have exactly 2 messages (3rd was rejected)');
        $this->assertEquals('msg-1', $consumedMessages[0]);
        $this->assertEquals('msg-2', $consumedMessages[1]);
    }

    /**
     * Test x-overflow: reject-publish-dlx
     * 
     * According to RabbitMQ docs:
     * - reject-publish-dlx: Rejects new publishes and dead-letters them
     * - Requires dead-letter exchange to be configured
     * 
     * Note: This test verifies the queue property is set correctly.
     * Full dead-letter testing requires DLX configuration.
     */
    public function testOverflowRejectPublishDlx()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Create queue with reject-publish-dlx
        $config = $this->createConfig([
            'x-max-length' => 1,
            'x-overflow' => 'reject-publish-dlx'
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Fill queue to max
        $message1 = new \Bschmitt\Amqp\Models\Message('msg-1', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
        ]);
        $publisher->publish($this->testRoutingKey, $message1);

        // Try to publish 2nd message - should be rejected and dead-lettered
        $message2 = new \Bschmitt\Amqp\Models\Message('msg-2', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
        ]);
        $publisher->publish($this->testRoutingKey, $message2);

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume - should only have 1 message (2nd was rejected)
        $consumer = new Consumer($config);
        $consumer->setup();

        $consumedMessages = [];
        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) use (&$consumedMessages) {
                $consumedMessages[] = $message->body;
                $resolver->acknowledge($message);
                $resolver->stopWhenProcessed();
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        // Should only have 1 message (2nd was rejected and dead-lettered)
        $this->assertCount(1, $consumedMessages, 'Should have exactly 1 message (2nd was rejected)');
        $this->assertEquals('msg-1', $consumedMessages[0]);
    }

    /**
     * Test both x-max-length and x-max-length-bytes together
     * 
     * According to RabbitMQ docs:
     * - If both are set, whichever limit is hit first will be enforced
     */
    public function testMaxLengthAndMaxLengthBytesTogether()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Set both limits - whichever is hit first applies
        $config = $this->createConfig([
            'x-max-length' => 5,
            'x-max-length-bytes' => 50 // Small byte limit
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Publish messages - byte limit should be hit first
        for ($i = 1; $i <= 10; $i++) {
            $messageText = "Msg-$i-" . str_repeat('x', 5); // ~15 bytes each
            $message = new \Bschmitt\Amqp\Models\Message($messageText, [
                'content_type' => 'text/plain',
                'delivery_mode' => 2
            ]);
            $publisher->publish($this->testRoutingKey, $message);
            usleep(50000);
        }

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume - should have messages but less than 10 (byte limit hit first)
        $consumer = new Consumer($config);
        $consumer->setup();

        $consumedMessages = [];
        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) use (&$consumedMessages) {
                $consumedMessages[] = $message->body;
                $resolver->acknowledge($message);
                $resolver->stopWhenProcessed();
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        // Should have some messages, but byte limit should prevent all 10
        $this->assertGreaterThan(0, count($consumedMessages), 'Should have some messages');
        $this->assertLessThan(10, count($consumedMessages), 'Byte limit should prevent all messages');
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


