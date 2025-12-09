<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Publisher;
use Bschmitt\Amqp\Consumer;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for queue max length feature
 * Requires RabbitMQ running on localhost:5672
 * 
 * To run this test:
 * 1. Start RabbitMQ: docker-compose up -d rabbit
 * 2. Run: php vendor/bin/phpunit test/QueueMaxLengthIntegrationTest.php
 * 
 * Fixes issue #120: https://github.com/bschmitt/laravel-amqp/issues/120
 * 
 * RabbitMQ Queue Length Documentation:
 * - x-max-length: Maximum number of messages (only ready messages count)
 * - Default overflow: 'drop-head' (drops oldest messages from front)
 * - Other overflow options: 'reject-publish', 'reject-publish-dlx'
 * 
 * Reference: https://www.rabbitmq.com/maxlength.html
 */
class QueueMaxLengthIntegrationTest extends TestCase
{
    private $configRepository;
    private $testQueueName;
    private $testExchange;
    private $testRoutingKey;

    protected function setUp() : void
    {
        parent::setUp();

        // Generate unique queue name for each test
        $this->testQueueName = 'test-max-length-' . uniqid();
        $this->testExchange = 'test-exchange-' . uniqid();
        $this->testRoutingKey = 'test.routing.key';

        // Create config with max length = 1
        $config = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => [
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
                        'queue_durable' => false, // Non-durable for easier cleanup
                        'queue_exclusive' => false,
                        'queue_auto_delete' => true, // Auto-delete for cleanup
                        'queue_nowait' => false,
                        'queue_properties' => [
                            'x-max-length' => 1,
                            // x-overflow defaults to 'drop-head' which drops oldest messages
                            // Other options: 'reject-publish', 'reject-publish-dlx'
                        ],

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
                    ]
                ]
            ]
        ];

        $this->configRepository = new Repository($config);
    }

    /**
     * Test that queue with max-length=1 only keeps the latest message
     * 
     * According to RabbitMQ docs:
     * - Only ready messages count towards the limit (unacknowledged don't count)
     * - Default overflow behavior is 'drop-head' (drops oldest from front)
     * 
     * This test:
     * 1. Publishes 3 messages without consuming
     * 2. Verifies only 1 message remains in queue (the latest, oldest 2 dropped)
     * 3. Consumes and verifies it's the latest message
     */
    public function testQueueMaxLengthKeepsOnlyLatestMessage()
    {
        // Skip if RabbitMQ is not available
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available. Start with: docker-compose up -d rabbit');
        }

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Publish 3 messages without consuming
        $messages = [
            'message-1-oldest',
            'message-2-middle',
            'message-3-latest'
        ];

        foreach ($messages as $message) {
            $publisher->publish($this->testRoutingKey, $message);
            usleep(100000); // Small delay to ensure ordering
        }

        // Close publisher connection
        \Bschmitt\Amqp\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Now consume - should only get the latest message
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $consumedMessages = [];
        $messageReceived = false;

        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) use (&$consumedMessages, &$messageReceived) {
                $consumedMessages[] = $message->body;
                $messageReceived = true;
                $resolver->acknowledge($message);
                $resolver->stopWhenProcessed();
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected when stopWhenProcessed is called
        }

        // Cleanup
        \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        // Assertions
        $this->assertTrue($messageReceived, 'At least one message should be received');
        $this->assertCount(1, $consumedMessages, 'Only one message should be in the queue');
        $this->assertEquals('message-3-latest', $consumedMessages[0], 'The latest message should be the one consumed');
    }

    /**
     * Test that queue respects max-length when messages are consumed
     */
    public function testQueueMaxLengthWithConsumption()
    {
        // Skip if RabbitMQ is not available
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available. Start with: docker-compose up -d rabbit');
        }

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Publish first message and consume it
        $publisher->publish($this->testRoutingKey, 'first-message');
        \Bschmitt\Amqp\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $firstMessage = null;
        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) use (&$firstMessage) {
                $firstMessage = $message->body;
                $resolver->acknowledge($message);
                $resolver->stopWhenProcessed();
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        $this->assertEquals('first-message', $firstMessage);

        // Now publish 2 more messages without consuming
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $publisher->publish($this->testRoutingKey, 'second-message');
        $publisher->publish($this->testRoutingKey, 'third-message');
        \Bschmitt\Amqp\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume - should only get the latest
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $lastMessage = null;
        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) use (&$lastMessage) {
                $lastMessage = $message->body;
                $resolver->acknowledge($message);
                $resolver->stopWhenProcessed();
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        $this->assertEquals('third-message', $lastMessage, 'Only the latest message should be in queue');
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

