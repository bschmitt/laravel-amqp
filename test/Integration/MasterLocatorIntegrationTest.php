<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Request;
use Bschmitt\Amqp\Models\Message;
use Bschmitt\Amqp\Test\Support\IntegrationTestBase;
use Illuminate\Config\Repository;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * Integration tests for master locator feature (x-queue-master-locator)
 * 
 * These tests require a real RabbitMQ instance running.
 * Configure connection details in .env file:
 * - AMQP_HOST
 * - AMQP_PORT
 * - AMQP_USER
 * - AMQP_PASSWORD
 * - AMQP_VHOST
 * 
 * Note: Master locator is only relevant for mirrored queues (deprecated).
 * Modern RabbitMQ installations may ignore this property or require HA policy.
 * RabbitMQ recommends using Quorum Queues instead of mirrored queues.
 * 
 * Reference: https://www.rabbitmq.com/docs/ha
 */
class MasterLocatorIntegrationTest extends IntegrationTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Use fixed queue name for master locator tests
        $this->testQueueName = 'test-queue-master-locator';
        $this->testExchange = 'test-exchange-master-locator';
        $this->testRoutingKey = 'test.routing.key';
        
        // Delete queue if it exists to start fresh (ensures clean state)
        $this->deleteQueue($this->testQueueName);
        
        // Update config with test queue and exchange
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['routing'] = $this->testRoutingKey;
        $config['properties']['test']['queue_durable'] = true; // Mirrored queues should be durable
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
     * Helper method to delete a queue
     */
    private function deleteQueue(string $queueName): void
    {
        try {
            // Create minimal config just for connection (don't declare queue)
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
            $request->connect(); // Only connect, don't setup (which declares queue)
            $channel = $request->getChannel();
            $channel->queue_delete($queueName, false, false);
            Request::shutdown($channel, $request->getConnection());
        } catch (\Exception $e) {
            // Queue might not exist, ignore error
        }
    }

    /**
     * Test that a queue can be declared with min-masters locator
     * Note: This may be ignored if HA policy is not configured
     */
    public function testQueueDeclareWithMinMastersLocator()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-master-locator' => 'min-masters'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Verify queue was declared successfully
        // Note: The master locator property may be ignored in non-HA setups
        $channel = $publisher->getChannel();
        $queueInfo = $channel->queue_declare($this->testQueueName, true); // passive=true to check existence
        
        $this->assertIsArray($queueInfo);
        $this->assertEquals($this->testQueueName, $queueInfo[0]);
        
        Request::shutdown($channel, $publisher->getConnection());
    }

    /**
     * Test that a queue can be declared with client-local locator
     */
    public function testQueueDeclareWithClientLocalLocator()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-master-locator' => 'client-local'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Verify queue was declared successfully
        $channel = $publisher->getChannel();
        $queueInfo = $channel->queue_declare($this->testQueueName, true);
        
        $this->assertIsArray($queueInfo);
        $this->assertEquals($this->testQueueName, $queueInfo[0]);
        
        Request::shutdown($channel, $publisher->getConnection());
    }

    /**
     * Test that a queue can be declared with random locator
     */
    public function testQueueDeclareWithRandomLocator()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-master-locator' => 'random'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Verify queue was declared successfully
        $channel = $publisher->getChannel();
        $queueInfo = $channel->queue_declare($this->testQueueName, true);
        
        $this->assertIsArray($queueInfo);
        $this->assertEquals($this->testQueueName, $queueInfo[0]);
        
        Request::shutdown($channel, $publisher->getConnection());
    }

    /**
     * Test that master locator can be combined with other queue properties
     */
    public function testMasterLocatorWithOtherProperties()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-master-locator' => 'min-masters',
            'x-max-length' => 100
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Verify queue was declared successfully
        $channel = $publisher->getChannel();
        $queueInfo = $channel->queue_declare($this->testQueueName, true);
        
        $this->assertIsArray($queueInfo);
        $this->assertEquals($this->testQueueName, $queueInfo[0]);
        
        Request::shutdown($channel, $publisher->getConnection());
    }

    /**
     * Test that messages can be published and consumed with master locator configured
     */
    public function testPublishAndConsumeWithMasterLocator()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-master-locator' => 'min-masters'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        
        $this->configRepository->set('amqp', $config);

        // Publish a message
        $testMessage = 'Master locator test message '  ;
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $publisher->publish($this->testRoutingKey, $message);
        
        // Small delay to ensure message is in queue
        usleep(100000); // 0.1 seconds
        
        // Consume the message
        $consumedMessages = [];
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $consumer->consume(
            $this->testQueueName,
            function ($msg, $resolver) use (&$consumedMessages) {
                $consumedMessages[] = $msg->body;
                $resolver->acknowledge($msg);
                $resolver->stopWhenProcessed();
            },
            [
                'timeout' => 2,
                'persistent' => true
            ]
        );
        
        // Verify message was consumed
        $this->assertCount(1, $consumedMessages);
        $this->assertEquals($testMessage, $consumedMessages[0]);
        
        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }
}

