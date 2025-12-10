<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Models\Message;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * Integration tests for lazy queue mode (x-queue-mode)
 * 
 * These tests require a real RabbitMQ instance running.
 * Configure connection details in .env file:
 * - AMQP_HOST
 * - AMQP_PORT
 * - AMQP_USER
 * - AMQP_PASSWORD
 * - AMQP_VHOST
 * 
 * Reference: https://www.rabbitmq.com/docs/lazy-queues
 */
class LazyQueueIntegrationTest extends IntegrationTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Use fixed queue name for lazy queue tests
        $this->testQueueName = 'test-queue-lazy';
        $this->testExchange = 'test-exchange-lazy';
        $this->testRoutingKey = 'test.routing.key';
        
        // Delete queue if it exists to start fresh (ensures clean state)
        $this->deleteQueue($this->testQueueName);
        
        // Update config with test queue and exchange
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['routing'] = $this->testRoutingKey;
        $config['properties']['test']['queue_durable'] = true; // Lazy queues should be durable
        $this->configRepository->set('amqp', $config);
    }

    protected function tearDown(): void
    {
        // Clean up: delete the test queue
        $this->deleteQueue($this->testQueueName);
        parent::tearDown();
    }

    /**
     * Test that a queue can be declared with lazy mode
     */
    public function testQueueDeclareWithLazyMode()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-mode' => 'lazy'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true; // Lazy queues should be durable
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Verify queue was declared successfully
        $channel = $publisher->getChannel();
        $queueInfo = $channel->queue_declare($this->testQueueName, true); // passive=true to check existence
        
        $this->assertIsArray($queueInfo);
        $this->assertEquals($this->testQueueName, $queueInfo[0]);
        
        \Bschmitt\Amqp\Core\Request::shutdown($channel, $publisher->getConnection());
    }

    /**
     * Test that messages can be published and consumed with lazy queue mode
     */
    public function testPublishAndConsumeWithLazyMode()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-mode' => 'lazy'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        
        $this->configRepository->set('amqp', $config);

        // Publish a message
        $testMessage = 'Lazy queue test message ' . time();
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $publisher->publish('test.routing.key', $message);
        
        // Give RabbitMQ time to process
        usleep(200000); // 0.2 seconds
        
        \Bschmitt\Amqp\Core\Request::shutdown(
            $publisher->getChannel(), 
            $publisher->getConnection()
        );

        // Consume the message
        $consumedMessages = [];
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessages) {
            $consumedMessages[] = $msg->body;
            $resolver->acknowledge($msg);
            $resolver->stopWhenProcessed();
        });
        
        \Bschmitt\Amqp\Core\Request::shutdown(
            $consumer->getChannel(), 
            $consumer->getConnection()
        );

        $this->assertCount(1, $consumedMessages);
        $this->assertEquals($testMessage, $consumedMessages[0]);
    }

    /**
     * Test that lazy queue mode can be combined with other properties
     */
    public function testLazyModeWithMaxLength()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-mode' => 'lazy',
            'x-max-length' => 2
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        
        $this->configRepository->set('amqp', $config);

        // Publish 3 messages (only 2 should be retained due to x-max-length)
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        
        for ($i = 1; $i <= 3; $i++) {
            $message = new Message("Lazy max-length test message $i", ['content_type' => 'text/plain']);
            $publisher->publish('test.routing.key', $message);
            usleep(100000); // 0.1 seconds between messages
        }
        
        \Bschmitt\Amqp\Core\Request::shutdown(
            $publisher->getChannel(), 
            $publisher->getConnection()
        );

        // Wait for queue to process
        sleep(1);

        // Check queue message count
        // Need to declare queue again to get message count
        $checkConsumer = new Consumer($this->configRepository);
        $checkConsumer->setup();
        $queueInfo = $checkConsumer->getChannel()->queue_declare($this->testQueueName, true, true, false, false); // passive=true, durable=true
        $messageCount = $queueInfo[1]; // Second element is message count
        
        \Bschmitt\Amqp\Core\Request::shutdown(
            $checkConsumer->getChannel(), 
            $checkConsumer->getConnection()
        );

        // Should have 2 messages (max-length=2, oldest dropped)
        $this->assertEquals(2, $messageCount);
    }

    /**
     * Test that default mode queue works normally
     */
    public function testDefaultModeQueue()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-mode' => 'default'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        
        $this->configRepository->set('amqp', $config);

        // Publish and consume should work normally
        $testMessage = 'Default mode test message ' . time();
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $publisher->publish('test.routing.key', $message);
        
        usleep(200000);
        
        \Bschmitt\Amqp\Core\Request::shutdown(
            $publisher->getChannel(), 
            $publisher->getConnection()
        );

        $consumedMessages = [];
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessages) {
            $consumedMessages[] = $msg->body;
            $resolver->acknowledge($msg);
            $resolver->stopWhenProcessed();
        });
        
        \Bschmitt\Amqp\Core\Request::shutdown(
            $consumer->getChannel(), 
            $consumer->getConnection()
        );

        $this->assertCount(1, $consumedMessages);
        $this->assertEquals($testMessage, $consumedMessages[0]);
    }

    /**
     * Helper method to delete a queue
     */
    private function deleteQueue(string $queueName): void
    {
        try {
            $consumer = new Consumer($this->configRepository);
            $consumer->setup();
            $channel = $consumer->getChannel();
            $channel->queue_delete($queueName, false, false);
            \Bschmitt\Amqp\Core\Request::shutdown($channel, $consumer->getConnection());
        } catch (\Exception $e) {
            // Queue might not exist, ignore error
        }
    }
}

