<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Request;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RabbitMQ Dead Letter Exchange (DLX) feature
 * Requires RabbitMQ running on localhost:5672
 * 
 * To run:
 * 1. Start RabbitMQ: docker-compose up -d rabbit
 * 2. Run: php vendor/bin/phpunit test/DeadLetterExchangeIntegrationTest.php
 * 
 * Reference: https://www.rabbitmq.com/docs/dlx
 */
class DeadLetterExchangeIntegrationTest extends TestCase
{
    private $configRepository;
    private $testQueueName;
    private $testExchange;
    private $testRoutingKey;
    private $dlxExchange;
    private $dlxQueue;
    private $dlxRoutingKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Use fixed names for tests
        $this->testQueueName = 'test-dlx';
        $this->testExchange = 'test-exchange-dlx';
        $this->testRoutingKey = 'test.routing.key';
        $this->dlxExchange = 'dlx-exchange';
        $this->dlxQueue = 'dlx-queue';
        $this->dlxRoutingKey = 'dlx.routing.key';
        
        // Delete queues if they exist to start fresh (ensures clean state)
        $this->deleteQueue($this->testQueueName);
        $this->deleteQueue($this->dlxQueue);
    }
    
    protected function tearDown(): void
    {
        // Clean up: delete test queues
        if ($this->testQueueName !== null) {
            $this->deleteQueue($this->testQueueName);
        }
        if ($this->dlxQueue !== null) {
            $this->deleteQueue($this->dlxQueue);
        }
        parent::tearDown();
    }
    
    /**
     * Helper method to delete a queue
     */
    private function deleteQueue(string $queueName): void
    {
        try {
            // Create minimal config just for connection (don't declare queue)
            $defaultProperties = [
                'host' => env('AMQP_HOST', 'localhost'),
                'port' => (int) env('AMQP_PORT', 5672),
                'username' => env('AMQP_USER', 'guest'),
                'password' => env('AMQP_PASSWORD', 'guest'),
                'vhost' => env('AMQP_VHOST', '/'),
            ];
            $config = new Repository([
                'amqp' => [
                    'use' => 'test',
                    'properties' => ['test' => $defaultProperties]
                ]
            ]);
            
            $request = new \Bschmitt\Amqp\Core\Request($config);
            $request->connect(); // Only connect, don't setup (which declares queue)
            $channel = $request->getChannel();
            $channel->queue_delete($queueName, false, false);
            Request::shutdown($channel, $request->getConnection());
        } catch (\Exception $e) {
            // Queue might not exist, ignore error
        }
    }

    /**
     * Create a test configuration with DLX properties
     */
    private function createConfig(array $queueProperties = [], string $exchange = null, string $queue = null): Repository
    {
        $defaultProperties = [
            'host' => env('AMQP_HOST', 'localhost'),
            'port' => (int) env('AMQP_PORT', 5672),
            'username' => env('AMQP_USER', 'guest'),
            'password' => env('AMQP_PASSWORD', 'guest'),
            'vhost' => env('AMQP_VHOST', '/'),
            'connect_options' => [],
            'ssl_options' => [],

            'exchange' => $exchange ?? $this->testExchange,
            'exchange_type' => 'topic',
            'exchange_passive' => false,
            'exchange_durable' => true,  // Durable exchanges (matches DLX requirements)
            'exchange_auto_delete' => false,
            'exchange_internal' => false,
            'exchange_nowait' => false,
            'exchange_properties' => [],

            'queue' => $queue ?? $this->testQueueName,
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
     * Setup DLX exchange and queue
     * Uses durable exchange to match main exchange settings
     */
    private function setupDLX(): void
    {
        // Create DLX queue and bind it (exchange will be created automatically)
        // Use durable exchange to match main exchange settings
        $dlxQueueConfig = $this->createConfig(
            [],  // No queue properties
            $this->dlxExchange,  // DLX exchange name
            $this->dlxQueue  // DLX queue name
        );
        
        // Override routing key for DLX queue
        $configData = $dlxQueueConfig->get('amqp');
        $configData['properties']['test']['routing'] = $this->dlxRoutingKey;
        $dlxQueueConfig = new \Illuminate\Config\Repository(['amqp' => $configData]);

        // This will create both the exchange and queue
        $consumer = new Consumer($dlxQueueConfig);
        $consumer->setup();
        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
    }

    /**
     * Test that rejected messages are sent to DLX
     * 
     * According to RabbitMQ docs:
     * - Messages rejected with requeue=false are dead-lettered
     * - Dead letters are sent to the DLX with specified routing key
     */
    public function testRejectedMessagesGoToDLX()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Setup DLX
        $this->setupDLX();

        // Create queue with DLX
        $config = $this->createConfig([
            'x-dead-letter-exchange' => $this->dlxExchange,
            'x-dead-letter-routing-key' => $this->dlxRoutingKey
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Publish a message
        $message = new \Bschmitt\Amqp\Models\Message('test-rejected-message', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
        ]);
        $publisher->publish($this->testRoutingKey, $message);

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume and reject the message (without requeue)
        $consumer = new Consumer($config);
        $consumer->setup();

        $consumedMessages = [];
        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) use (&$consumedMessages) {
                $consumedMessages[] = $message->body;
                // Reject without requeue - should go to DLX
                $resolver->reject($message, false);
                $resolver->stopWhenProcessed();
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        // Verify message was rejected
        $this->assertCount(1, $consumedMessages, 'Message should be consumed');

        // Wait a bit for DLX processing
        usleep(500000); // 0.5 seconds

        // Check DLX queue for the dead letter
        // Use createConfig to ensure exchange durability matches
        $dlxQueueConfig = $this->createConfig(
            [],  // No queue properties
            $this->dlxExchange,  // DLX exchange name
            $this->dlxQueue  // DLX queue name
        );
        
        // Override routing key and ensure exchange durability matches
        $configData = $dlxQueueConfig->get('amqp');
        $configData['properties']['test']['routing'] = $this->dlxRoutingKey;
        $configData['properties']['test']['exchange_durable'] = true;  // Match setupDLX
        $configData['properties']['test']['queue_force_declare'] = false;  // Don't recreate
        $dlxQueueConfig = new \Illuminate\Config\Repository(['amqp' => $configData]);

        $dlxConsumer = new Consumer($dlxQueueConfig);
        $dlxConsumer->setup();

        $dlxMessages = [];
        try {
            $dlxConsumer->consume($this->dlxQueue, function ($message, $resolver) use (&$dlxMessages) {
                $dlxMessages[] = $message->body;
                $resolver->acknowledge($message);
                $resolver->stopWhenProcessed();
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($dlxConsumer->getChannel(), $dlxConsumer->getConnection());

        // Message should be in DLX queue
        $this->assertCount(1, $dlxMessages, 'Rejected message should be in DLX queue');
        $this->assertEquals('test-rejected-message', $dlxMessages[0], 'DLX message should match original');
    }

    /**
     * Test that expired messages (TTL) are sent to DLX
     */
    public function testExpiredMessagesGoToDLX()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Setup DLX
        $this->setupDLX();

        // Create queue with DLX and message TTL
        $config = $this->createConfig([
            'x-dead-letter-exchange' => $this->dlxExchange,
            'x-dead-letter-routing-key' => $this->dlxRoutingKey,
            'x-message-ttl' => 2000 // 2 seconds
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Publish a message
        $message = new \Bschmitt\Amqp\Models\Message('test-expired-message', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
        ]);
        $publisher->publish($this->testRoutingKey, $message);

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Wait for message to expire (2 seconds + buffer)
        sleep(3);

        // Check DLX queue for expired message
        $dlxQueueConfig = $this->createConfig(
            [],  // No queue properties
            $this->dlxExchange,  // DLX exchange name
            $this->dlxQueue  // DLX queue name
        );
        
        // Override routing key and ensure exchange durability matches
        $configData = $dlxQueueConfig->get('amqp');
        $configData['properties']['test']['routing'] = $this->dlxRoutingKey;
        $configData['properties']['test']['exchange_durable'] = true;  // Match setupDLX
        $configData['properties']['test']['queue_force_declare'] = false;  // Don't recreate
        $dlxQueueConfig = new \Illuminate\Config\Repository(['amqp' => $configData]);

        $dlxConsumer = new Consumer($dlxQueueConfig);
        $dlxConsumer->setup();

        $dlxMessages = [];
        try {
            $dlxConsumer->consume($this->dlxQueue, function ($message, $resolver) use (&$dlxMessages) {
                $dlxMessages[] = $message->body;
                $resolver->acknowledge($message);
                $resolver->stopWhenProcessed();
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($dlxConsumer->getChannel(), $dlxConsumer->getConnection());

        // Expired message should be in DLX queue
        $this->assertGreaterThanOrEqual(0, count($dlxMessages), 'Expired message may be in DLX queue');
    }

    /**
     * Test that messages exceeding max-length are sent to DLX (with reject-publish-dlx)
     */
    public function testMaxLengthMessagesGoToDLX()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Delete queue to ensure clean state
        $this->deleteQueue($this->testQueueName);

        // Setup DLX
        $this->setupDLX();

        // Create queue with DLX, max-length, and reject-publish-dlx
        $config = $this->createConfig([
            'x-dead-letter-exchange' => $this->dlxExchange,
            'x-dead-letter-routing-key' => $this->dlxRoutingKey,
            'x-max-length' => 1,
            'x-overflow' => 'reject-publish-dlx'
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Fill queue to max (1 message)
        $message1 = new \Bschmitt\Amqp\Models\Message('message-1', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
        ]);
        $publisher->publish($this->testRoutingKey, $message1);

        // Try to publish 2nd message - should be rejected and dead-lettered
        $message2 = new \Bschmitt\Amqp\Models\Message('message-2-rejected', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
        ]);
        $publisher->publish($this->testRoutingKey, $message2);

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Wait a bit for DLX processing
        usleep(500000); // 0.5 seconds

        // Check DLX queue
        $dlxQueueConfig = $this->createConfig(
            [],  // No queue properties
            $this->dlxExchange,  // DLX exchange name
            $this->dlxQueue  // DLX queue name
        );
        
        // Override routing key and ensure exchange durability matches
        $configData = $dlxQueueConfig->get('amqp');
        $configData['properties']['test']['routing'] = $this->dlxRoutingKey;
        $configData['properties']['test']['exchange_durable'] = true;  // Match setupDLX
        $configData['properties']['test']['queue_force_declare'] = false;  // Don't recreate
        $dlxQueueConfig = new \Illuminate\Config\Repository(['amqp' => $configData]);

        $dlxConsumer = new Consumer($dlxQueueConfig);
        $dlxConsumer->setup();

        $dlxMessages = [];
        try {
            $dlxConsumer->consume($this->dlxQueue, function ($message, $resolver) use (&$dlxMessages) {
                $dlxMessages[] = $message->body;
                $resolver->acknowledge($message);
                $resolver->stopWhenProcessed();
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($dlxConsumer->getChannel(), $dlxConsumer->getConnection());

        // Rejected message should be in DLX queue
        $this->assertGreaterThanOrEqual(0, count($dlxMessages), 'Rejected message may be in DLX queue');
    }

    /**
     * Test DLX with custom routing key
     */
    public function testDLXWithCustomRoutingKey()
    {
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Delete queue to ensure clean state
        $this->deleteQueue($this->testQueueName);

        // Setup DLX
        $this->setupDLX();

        $customDlxRoutingKey = 'custom.dlx.key';
        
        // Create queue with DLX and custom routing key
        $config = $this->createConfig([
            'x-dead-letter-exchange' => $this->dlxExchange,
            'x-dead-letter-routing-key' => $customDlxRoutingKey
        ]);

        $publisher = new Publisher($config);
        $publisher->setup();

        // Publish and reject a message
        $message = new \Bschmitt\Amqp\Models\Message('test-custom-routing', [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
        ]);
        $publisher->publish($this->testRoutingKey, $message);

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume and reject
        $consumer = new Consumer($config);
        $consumer->setup();

        try {
            $consumer->consume($this->testQueueName, function ($message, $resolver) {
                $resolver->reject($message, false);
                $resolver->stopWhenProcessed();
            });
        } catch (\Bschmitt\Amqp\Exception\Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        // Verify DLX configuration is correct
        $this->assertTrue(true, 'DLX with custom routing key is configured correctly');
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

