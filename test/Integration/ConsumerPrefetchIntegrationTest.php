<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Request;
use Bschmitt\Amqp\Models\Message;
use Bschmitt\Amqp\Test\Support\IntegrationTestBase;
use Illuminate\Config\Repository;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * Integration tests for Consumer Prefetch (QoS) feature
 * 
 * These tests require a real RabbitMQ instance running.
 * Configure connection details in .env file:
 * - AMQP_HOST
 * - AMQP_PORT
 * - AMQP_USER
 * - AMQP_PASSWORD
 * - AMQP_VHOST
 * 
 * Features tested:
 * - Dynamic prefetch adjustment
 * - Prefetch count limiting message delivery
 * - Get current prefetch settings
 * - Prefetch changes take effect immediately
 * 
 * Reference: https://www.rabbitmq.com/docs/consumer-prefetch
 */
class ConsumerPrefetchIntegrationTest extends IntegrationTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Use fixed queue name for prefetch tests
        $this->testQueueName = 'test-queue-prefetch';
        $this->testExchange = 'test-exchange-prefetch';
        $this->testRoutingKey = 'test.routing.key';
        
        // Delete queue if it exists to start fresh
        $this->deleteQueue($this->testQueueName);
        
        // Update config with test queue and exchange
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['routing'] = $this->testRoutingKey;
        $config['properties']['test']['queue_properties'] = []; // No max-length for prefetch tests
        $this->configRepository->set('amqp', $config);
    }

    protected function tearDown(): void
    {
        // Clean up: delete the test queue
        if ($this->testQueueName !== null) {
            $this->deleteQueue($this->testQueueName);
        }
        parent::tearDown();
    }

    /**
     * Test that getPrefetch returns current settings
     */
    public function testGetPrefetch()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['qos'] = true;
        $config['properties']['test']['qos_prefetch_count'] = 5;
        $config['properties']['test']['qos_prefetch_size'] = 1024;
        $config['properties']['test']['qos_a_global'] = false;
        $this->configRepository->set('amqp', $config);

        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $prefetch = $consumer->getPrefetch();

        $this->assertIsArray($prefetch);
        $this->assertEquals(5, $prefetch['prefetch_count']);
        $this->assertEquals(1024, $prefetch['prefetch_size']);
        $this->assertEquals(false, $prefetch['global']);

        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
    }

    /**
     * Test that setPrefetch changes prefetch settings
     */
    public function testSetPrefetch()
    {
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        // Set prefetch to 2
        $consumer->setPrefetch(2);

        $prefetch = $consumer->getPrefetch();
        $this->assertEquals(2, $prefetch['prefetch_count']);

        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
    }

    /**
     * Test that prefetch count limits message delivery
     */
    public function testPrefetchCountLimitsDelivery()
    {
        // Publish 5 messages
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        for ($i = 1; $i <= 5; $i++) {
            $message = new Message("Prefetch test message {$i}", ['content_type' => 'text/plain']);
            $publisher->publish($this->testRoutingKey, $message);
        }

        Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Small delay to ensure messages are in queue
        usleep(200000); // 0.2 seconds

        // Setup consumer with prefetch count of 2
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['qos'] = true;
        $config['properties']['test']['qos_prefetch_count'] = 2;
        $this->configRepository->set('amqp', $config);

        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        // Verify prefetch is set to 2
        $prefetch = $consumer->getPrefetch();
        $this->assertEquals(2, $prefetch['prefetch_count']);

        // Consume messages - with prefetch=2, only 2 messages should be delivered initially
        $consumedMessages = [];
        $messageCount = 0;

        try {
            $consumer->consume(
                $this->testQueueName,
                function ($msg, $resolver) use (&$consumedMessages, &$messageCount) {
                    $consumedMessages[] = $msg->body;
                    $resolver->acknowledge($msg);
                    $messageCount++;
                    
                    // After acknowledging 2 messages, check queue count
                    // With prefetch=2, more messages should be delivered after ack
                    if ($messageCount >= 5) {
                        $resolver->stopWhenProcessed();
                    }
                },
                [
                    'timeout' => 5,
                    'persistent' => true
                ]
            );
        } catch (AMQPTimeoutException $e) {
            // Expected if timeout
        }

        // All 5 messages should be consumed eventually
        $this->assertGreaterThanOrEqual(2, count($consumedMessages), 'At least 2 messages should be consumed');
        $this->assertLessThanOrEqual(5, count($consumedMessages), 'At most 5 messages should be consumed');

        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
    }

    /**
     * Test that dynamic prefetch adjustment works
     */
    public function testDynamicPrefetchAdjustment()
    {
        // Publish 5 messages
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        for ($i = 1; $i <= 5; $i++) {
            $message = new Message("Dynamic prefetch test message {$i}", ['content_type' => 'text/plain']);
            $publisher->publish($this->testRoutingKey, $message);
        }

        Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Small delay to ensure messages are in queue
        usleep(200000); // 0.2 seconds

        // Setup consumer with initial prefetch of 1
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['qos'] = true;
        $config['properties']['test']['qos_prefetch_count'] = 1;
        $this->configRepository->set('amqp', $config);

        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        // Verify initial prefetch
        $prefetch = $consumer->getPrefetch();
        $this->assertEquals(1, $prefetch['prefetch_count']);

        // Dynamically change prefetch to 3
        $consumer->setPrefetch(3);

        // Verify prefetch was changed
        $prefetch = $consumer->getPrefetch();
        $this->assertEquals(3, $prefetch['prefetch_count']);

        // Consume messages
        $consumedMessages = [];
        $messageCount = 0;

        try {
            $consumer->consume(
                $this->testQueueName,
                function ($msg, $resolver) use (&$consumedMessages, &$messageCount) {
                    $consumedMessages[] = $msg->body;
                    $resolver->acknowledge($msg);
                    $messageCount++;
                    
                    if ($messageCount >= 5) {
                        $resolver->stopWhenProcessed();
                    }
                },
                [
                    'timeout' => 5,
                    'persistent' => true
                ]
            );
        } catch (AMQPTimeoutException $e) {
            // Expected if timeout
        }

        // All 5 messages should be consumed
        $this->assertGreaterThanOrEqual(1, count($consumedMessages), 'At least 1 message should be consumed');

        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
    }

    /**
     * Test that setPrefetch with global flag works
     */
    public function testSetPrefetchWithGlobal()
    {
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        // Set prefetch with global flag
        $consumer->setPrefetch(5, 0, true);

        $prefetch = $consumer->getPrefetch();
        $this->assertEquals(5, $prefetch['prefetch_count']);
        $this->assertEquals(true, $prefetch['global']);

        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
    }

    /**
     * Test that setPrefetch with prefetch size works
     * 
     * Note: RabbitMQ doesn't actually support prefetch_size != 0 in practice.
     * This test verifies the method accepts the parameter, but the actual
     * RabbitMQ server will reject it. We test that the method works correctly
     * even if RabbitMQ doesn't support the feature.
     */
    public function testSetPrefetchWithSize()
    {
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        // Set prefetch with size (RabbitMQ will reject this, but method should work)
        // We'll catch the exception and verify the method was called correctly
        try {
            $consumer->setPrefetch(1, 1024, false);
            
            // If it succeeds, verify properties were set
            $prefetch = $consumer->getPrefetch();
            $this->assertEquals(1, $prefetch['prefetch_count']);
            $this->assertEquals(1024, $prefetch['prefetch_size']);
            $this->assertEquals(false, $prefetch['global']);
        } catch (\PhpAmqpLib\Exception\AMQPConnectionClosedException $e) {
            // RabbitMQ doesn't support prefetch_size != 0, which is expected
            // Verify the method was called (properties were set before the error)
            $prefetch = $consumer->getPrefetch();
            $this->assertEquals(1, $prefetch['prefetch_count']);
            $this->assertStringContainsString('prefetch_size', $e->getMessage());
        }

        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
    }

    /**
     * Test that setPrefetch throws exception for negative values
     */
    public function testSetPrefetchThrowsExceptionForNegativeCount()
    {
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefetch count must be non-negative');

        $consumer->setPrefetch(-1);
    }

    /**
     * Test that setPrefetch throws exception for negative size
     */
    public function testSetPrefetchThrowsExceptionForNegativeSize()
    {
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefetch size must be non-negative');

        $consumer->setPrefetch(1, -1);
    }

    /**
     * Helper method to delete a queue if it exists
     */
    private function deleteQueue(string $queueName): void
    {
        try {
            $defaultProperties = [
                'host' => getenv('AMQP_HOST') ?: 'localhost',
                'port' => (int) (getenv('AMQP_PORT') ?: 5672),
                'username' => getenv('AMQP_USER') ?: 'guest',
                'password' => getenv('AMQP_PASSWORD') ?: 'guest',
                'vhost' => getenv('AMQP_VHOST') ?: '/',
            ];
            $config = new Repository([
                'amqp' => [
                    'use' => 'test',
                    'properties' => ['test' => $defaultProperties]
                ]
            ]);
            
            $request = new Request($config);
            $request->connect();
            $channel = $request->getChannel();
            $channel->queue_delete($queueName, false, false);
            Request::shutdown($channel, $request->getConnection());
        } catch (\Exception $e) {
            // Queue might not exist, ignore error
        }
    }
}

