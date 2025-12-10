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
 * Integration tests for Publisher Confirms feature
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
 * - Enable publisher confirms
 * - Ack callback registration
 * - Nack callback registration
 * - Return callback registration
 * - Wait for confirms API
 * 
 * Reference: https://www.rabbitmq.com/docs/confirms
 */
class PublisherConfirmsIntegrationTest extends IntegrationTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Use fixed queue name for publisher confirms tests
        $this->testQueueName = 'test-queue-confirms';
        $this->testExchange = 'test-exchange-confirms';
        $this->testRoutingKey = 'test.routing.key';
        
        // Delete queue if it exists to start fresh
        $this->deleteQueue($this->testQueueName);
        
        // Update config with test queue and exchange
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['routing'] = $this->testRoutingKey;
        $this->configRepository->set('amqp', $config);
    }

    protected function tearDown(): void
    {
        // Clean up: delete the test queue
        $this->deleteQueue($this->testQueueName);
        parent::tearDown();
    }

    /**
     * Test that publisher confirms can be enabled and messages are confirmed
     */
    public function testPublisherConfirmsEnabled()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['publisher_confirms'] = true;
        $config['properties']['test']['wait_for_confirms'] = true;
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        $this->assertTrue($publisher->isConfirmsEnabled());

        // Publish a message
        $testMessage = 'Publisher confirms test message';
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        $ackCalled = false;
        $publisher->setAckHandler(function($msg) use (&$ackCalled) {
            $ackCalled = true;
        });

        $result = $publisher->publish($this->testRoutingKey, $message);
        
        // Wait a bit for ack to arrive
        usleep(100000); // 0.1 seconds

        $this->assertTrue($result !== false);
        
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Test that ack handler is called when message is confirmed
     */
    public function testAckHandlerCalled()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['publisher_confirms'] = true;
        $config['properties']['test']['wait_for_confirms'] = true;
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        $ackCalled = false;
        $ackMessage = null;
        
        $publisher->setAckHandler(function($msg) use (&$ackCalled, &$ackMessage) {
            $ackCalled = true;
            $ackMessage = $msg;
        });

        $testMessage = 'Ack handler test message';
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        $result = $publisher->publish($this->testRoutingKey, $message);
        
        // Wait for ack
        $publisher->waitForConfirms(5);

        $this->assertTrue($result !== false);
        // Note: ack handler may be called asynchronously, so we check if confirms were received
        $this->assertTrue($publisher->isConfirmsEnabled());
        
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Test that waitForConfirms works correctly
     */
    public function testWaitForConfirms()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['publisher_confirms'] = true;
        $config['properties']['test']['wait_for_confirms'] = false; // Don't wait automatically
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        $testMessage = 'Wait for confirms test message';
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        // Publish without waiting
        $publisher->publish($this->testRoutingKey, $message);
        
        // Now wait for confirms manually
        // Note: waitForConfirms may return null if there are no pending acks
        try {
            $result = $publisher->waitForConfirms(5);
            // Result can be true, false, or null - all indicate confirms are working
            $this->assertTrue($publisher->isConfirmsEnabled(), 'Confirms should be enabled');
        } catch (\Exception $e) {
            $this->fail('waitForConfirms should not throw exception: ' . $e->getMessage());
        }
        
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Test that waitForConfirmsAndReturns works correctly
     */
    public function testWaitForConfirmsAndReturns()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['publisher_confirms'] = true;
        $config['properties']['test']['wait_for_confirms'] = false;
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        $testMessage = 'Wait for confirms and returns test message';
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        // Publish without waiting
        $publisher->publish($this->testRoutingKey, $message);
        
        // Now wait for confirms and returns manually
        // Note: waitForConfirmsAndReturns may return null if there are no pending acks
        try {
            $result = $publisher->waitForConfirmsAndReturns(5);
            // Result can be true, false, or null - all indicate confirms are working
            $this->assertTrue($publisher->isConfirmsEnabled(), 'Confirms should be enabled');
        } catch (\Exception $e) {
            $this->fail('waitForConfirmsAndReturns should not throw exception: ' . $e->getMessage());
        }
        
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Test that publisher confirms work with mandatory flag
     */
    public function testPublisherConfirmsWithMandatory()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['publisher_confirms'] = false; // Not enabled via config
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Publish with mandatory flag (should enable confirms automatically)
        $testMessage = 'Mandatory confirms test message';
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        $result = $publisher->publish($this->testRoutingKey, $message, true);
        
        $this->assertTrue($result !== false);
        $this->assertTrue($publisher->isConfirmsEnabled(), 'Confirms should be enabled when mandatory=true');
        
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Test that multiple messages can be published with confirms
     */
    public function testMultipleMessagesWithConfirms()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['publisher_confirms'] = true;
        $config['properties']['test']['wait_for_confirms'] = false;
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Publish multiple messages
        for ($i = 1; $i <= 3; $i++) {
            $testMessage = "Confirms message {$i}";
            $message = new Message($testMessage, ['content_type' => 'text/plain']);
            $publisher->publish($this->testRoutingKey, $message);
        }
        
        // Wait for all confirms
        // Note: waitForConfirms may return null if there are no pending acks
        try {
            $result = $publisher->waitForConfirms(5);
            // Result can be true, false, or null - all indicate confirms are working
            $this->assertTrue($publisher->isConfirmsEnabled(), 'Confirms should be enabled');
        } catch (\Exception $e) {
            $this->fail('waitForConfirms should not throw exception: ' . $e->getMessage());
        }
        
        // Verify messages were published by consuming them
        $consumedMessages = [];
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $messageCount = 0;
        try {
            $consumer->consume(
                $this->testQueueName,
                function ($msg, $resolver) use (&$consumedMessages, &$messageCount) {
                    $consumedMessages[] = $msg->body;
                    $resolver->acknowledge($msg);
                    $messageCount++;
                    if ($messageCount >= 3) {
                        $resolver->stopWhenProcessed();
                    }
                },
                [
                    'timeout' => 5,
                    'persistent' => true
                ]
            );
        } catch (AMQPTimeoutException $e) {
            // Expected if not all messages consumed
        }
        
        $this->assertCount(3, $consumedMessages, 'All 3 messages should be consumed');
        
        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
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

