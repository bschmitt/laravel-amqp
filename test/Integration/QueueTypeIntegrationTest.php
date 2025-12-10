<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Request;
use Bschmitt\Amqp\Models\Message;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * Integration tests for queue type (x-queue-type)
 * 
 * These tests require a real RabbitMQ instance running.
 * Configure connection details in .env file:
 * - AMQP_HOST
 * - AMQP_PORT
 * - AMQP_USER
 * - AMQP_PASSWORD
 * - AMQP_VHOST
 * 
 * Note: Quorum queues require RabbitMQ 3.8.0+
 * Note: Stream queues require RabbitMQ 3.9.0+
 * 
 * Reference: 
 * - https://www.rabbitmq.com/docs/quorum-queues
 * - https://www.rabbitmq.com/docs/streams
 */
class QueueTypeIntegrationTest extends IntegrationTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Use fixed queue name for queue type tests
        $this->testQueueName = 'test-queue-type';
        $this->testExchange = 'test-exchange-type';
        $this->testRoutingKey = 'test.routing.key';
        
        // Delete queue if it exists to start fresh (ensures clean state)
        $this->deleteQueue($this->testQueueName);
        
        // Update config with test queue and exchange
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['routing'] = $this->testRoutingKey;
        $config['properties']['test']['queue_durable'] = true; // Quorum and stream queues must be durable
        $this->configRepository->set('amqp', $config);
    }

    protected function tearDown(): void
    {
        // Clean up: delete the test queue
        $this->deleteQueue($this->testQueueName);
        parent::tearDown();
    }

    /**
     * Test that a queue can be declared with classic type
     */
    public function testQueueDeclareWithClassicType()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-type' => 'classic'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Verify queue was declared successfully
        $channel = $publisher->getChannel();
        $queueInfo = $channel->queue_declare($this->testQueueName, true); // passive=true to check existence
        
        $this->assertIsArray($queueInfo);
        $this->assertEquals($this->testQueueName, $queueInfo[0]);
        
        Request::shutdown($channel, $publisher->getConnection());
    }

    /**
     * Test that messages can be published and consumed with classic queue type
     */
    public function testPublishAndConsumeWithClassicType()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-type' => 'classic'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        
        $this->configRepository->set('amqp', $config);

        // Publish a message
        $testMessage = 'Classic queue test message ' . time();
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $publisher->publish('test.routing.key', $message);
        
        // Give RabbitMQ time to process
        usleep(200000); // 0.2 seconds
        
        Request::shutdown(
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
        
        Request::shutdown(
            $consumer->getChannel(), 
            $consumer->getConnection()
        );

        $this->assertCount(1, $consumedMessages);
        $this->assertEquals($testMessage, $consumedMessages[0]);
    }

    /**
     * Test that a queue can be declared with quorum type
     * Note: Requires RabbitMQ 3.8.0+ and quorum queues enabled
     */
    public function testQueueDeclareWithQuorumType()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-type' => 'quorum'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true; // Quorum queues must be durable
        $config['properties']['test']['queue_auto_delete'] = false; // Quorum queues cannot be auto-delete
        $config['properties']['test']['queue_exclusive'] = false; // Quorum queues cannot be exclusive
        
        $this->configRepository->set('amqp', $config);

        try {
            $publisher = new Publisher($this->configRepository);
            $publisher->setup();

            // Verify queue was declared successfully
            $channel = $publisher->getChannel();
            $queueInfo = $channel->queue_declare($this->testQueueName, true); // passive=true to check existence
            
            $this->assertIsArray($queueInfo);
            $this->assertEquals($this->testQueueName, $queueInfo[0]);
            
            Request::shutdown($channel, $publisher->getConnection());
        } catch (\PhpAmqpLib\Exception\AMQPProtocolChannelException $e) {
            // Quorum queues might not be available (requires RabbitMQ 3.8.0+ and plugin enabled)
            if (strpos($e->getMessage(), 'NOT_FOUND') !== false || strpos($e->getMessage(), 'quorum') !== false) {
                $this->markTestSkipped('Quorum queues not available. Requires RabbitMQ 3.8.0+ with quorum queues enabled.');
            } else {
                throw $e;
            }
        }
    }

    /**
     * Test that messages can be published and consumed with quorum queue type
     */
    public function testPublishAndConsumeWithQuorumType()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-type' => 'quorum'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        $config['properties']['test']['queue_auto_delete'] = false; // Quorum queues cannot be auto-delete
        $config['properties']['test']['queue_exclusive'] = false; // Quorum queues cannot be exclusive
        
        $this->configRepository->set('amqp', $config);

        try {
            // Publish a message
            $testMessage = 'Quorum queue test message ' . time();
            $message = new Message($testMessage, ['content_type' => 'text/plain']);
            
            $publisher = new Publisher($this->configRepository);
            $publisher->setup();
            $publisher->publish('test.routing.key', $message);
            
            usleep(200000);
            
            Request::shutdown(
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
            
            Request::shutdown(
                $consumer->getChannel(), 
                $consumer->getConnection()
            );

            $this->assertCount(1, $consumedMessages);
            $this->assertEquals($testMessage, $consumedMessages[0]);
        } catch (\PhpAmqpLib\Exception\AMQPProtocolChannelException $e) {
            // Quorum queues might not be available
            if (strpos($e->getMessage(), 'NOT_FOUND') !== false || strpos($e->getMessage(), 'quorum') !== false) {
                $this->markTestSkipped('Quorum queues not available. Requires RabbitMQ 3.8.0+ with quorum queues enabled.');
            } else {
                throw $e;
            }
        }
    }

    /**
     * Test that queue type can be combined with other properties
     */
    public function testQueueTypeWithOtherProperties()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-type' => 'classic',
            'x-max-length' => 2
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        
        $this->configRepository->set('amqp', $config);

        // Publish 3 messages (only 2 should be retained due to x-max-length)
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        
        for ($i = 1; $i <= 3; $i++) {
            $message = new Message("Queue type max-length test message $i", ['content_type' => 'text/plain']);
            $publisher->publish('test.routing.key', $message);
            usleep(100000); // 0.1 seconds between messages
        }
        
        Request::shutdown(
            $publisher->getChannel(), 
            $publisher->getConnection()
        );

        // Wait for queue to process
        sleep(1);

        // Check queue message count
        $checkConsumer = new Consumer($this->configRepository);
        $checkConsumer->setup();
        $queueInfo = $checkConsumer->getChannel()->queue_declare($this->testQueueName, true, true, false, false);
        $messageCount = $queueInfo[1];
        
        Request::shutdown(
            $checkConsumer->getChannel(), 
            $checkConsumer->getConnection()
        );

        // Should have 2 messages (max-length=2, oldest dropped)
        $this->assertEquals(2, $messageCount);
    }

    /**
     * Helper method to delete a queue
     */
    private function deleteQueue(string $queueName): void
    {
        try {
            $request = new \Bschmitt\Amqp\Core\Request($this->configRepository);
            $request->connect(); // Only connect, don't setup (which declares queue)
            $channel = $request->getChannel();
            $channel->queue_delete($queueName, false, false);
            Request::shutdown($channel, $request->getConnection());
        } catch (\Exception $e) {
            // Queue might not exist, ignore error
        }
    }
}

