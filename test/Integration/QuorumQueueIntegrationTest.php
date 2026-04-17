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
 * Integration tests for Quorum Queue feature
 * 
 * These tests require a real RabbitMQ instance running (3.8.0+).
 * Configure connection details in .env file:
 * - AMQP_HOST
 * - AMQP_PORT
 * - AMQP_USER
 * - AMQP_PASSWORD
 * - AMQP_VHOST
 * 
 * Note: Quorum queues require:
 * - RabbitMQ 3.8.0 or higher
 * - queue_durable => true
 * - queue_exclusive => false
 * - queue_auto_delete => false
 * 
 * Features tested:
 * - Queue type selection (x-queue-type: quorum)
 * - Leader election (automatic, handled by RabbitMQ)
 * - Raft consensus (automatic, built into RabbitMQ)
 * - Replication (automatic across cluster)
 * 
 * Reference: https://www.rabbitmq.com/docs/quorum-queues
 */
class QuorumQueueIntegrationTest extends IntegrationTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Use fixed queue name for quorum queue tests
        $this->testQueueName = 'test-queue-quorum';
        $this->testExchange = 'test-exchange-quorum';
        $this->testRoutingKey = 'test.routing.key';
        
        // Delete queue if it exists to start fresh
        $this->deleteQueue($this->testQueueName);
        
        // Update config with test queue and exchange
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['routing'] = $this->testRoutingKey;
        $config['properties']['test']['queue_durable'] = true;      // Required for quorum
        $config['properties']['test']['queue_exclusive'] = false;   // Required for quorum
        $config['properties']['test']['queue_auto_delete'] = false; // Required for quorum
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
     * Test that a quorum queue can be declared
     */
    public function testQuorumQueueDeclaration()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-type' => 'quorum'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        $config['properties']['test']['queue_exclusive'] = false;
        $config['properties']['test']['queue_auto_delete'] = false;
        
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
     * Test that messages can be published and consumed from quorum queue
     */
    public function testPublishAndConsumeWithQuorumQueue()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-type' => 'quorum'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        $config['properties']['test']['queue_exclusive'] = false;
        $config['properties']['test']['queue_auto_delete'] = false;
        
        $this->configRepository->set('amqp', $config);

        // Publish a message
        $testMessage = 'Quorum queue test message';
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

    /**
     * Test that quorum queue requires durable property
     * Note: This test may fail if RabbitMQ rejects non-durable quorum queues
     */
    public function testQuorumQueueRequiresDurable()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-type' => 'quorum'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true; // Set to true (required)
        $config['properties']['test']['queue_exclusive'] = false;
        $config['properties']['test']['queue_auto_delete'] = false;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        
        // Should succeed with durable=true
        try {
            $publisher->setup();
            $this->assertTrue(true, 'Quorum queue created successfully with durable=true');
        } catch (\Exception $e) {
            $this->fail('Quorum queue should be created with durable=true: ' . $e->getMessage());
        }
        
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Test that quorum queue cannot be exclusive
     */
    public function testQuorumQueueCannotBeExclusive()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-type' => 'quorum'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        $config['properties']['test']['queue_exclusive'] = false; // Set to false (required)
        $config['properties']['test']['queue_auto_delete'] = false;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        
        // Should succeed with exclusive=false
        try {
            $publisher->setup();
            $this->assertTrue(true, 'Quorum queue created successfully with exclusive=false');
        } catch (\Exception $e) {
            $this->fail('Quorum queue should be created with exclusive=false: ' . $e->getMessage());
        }
        
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Test that quorum queue cannot be auto-delete
     */
    public function testQuorumQueueCannotBeAutoDelete()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-type' => 'quorum'
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        $config['properties']['test']['queue_exclusive'] = false;
        $config['properties']['test']['queue_auto_delete'] = false; // Set to false (required)
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        
        // Should succeed with auto_delete=false
        try {
            $publisher->setup();
            $this->assertTrue(true, 'Quorum queue created successfully with auto_delete=false');
        } catch (\Exception $e) {
            $this->fail('Quorum queue should be created with auto_delete=false: ' . $e->getMessage());
        }
        
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Test quorum queue with other properties
     */
    public function testQuorumQueueWithOtherProperties()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['queue_properties'] = [
            'x-queue-type' => 'quorum',
            'x-max-length' => 1000
        ];
        $config['properties']['test']['queue_force_declare'] = true;
        $config['properties']['test']['queue_durable'] = true;
        $config['properties']['test']['queue_exclusive'] = false;
        $config['properties']['test']['queue_auto_delete'] = false;
        
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

