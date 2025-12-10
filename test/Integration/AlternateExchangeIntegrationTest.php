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
 * Integration tests for alternate exchange feature (alternate-exchange)
 * 
 * These tests require a real RabbitMQ instance running.
 * Configure connection details in .env file:
 * - AMQP_HOST
 * - AMQP_PORT
 * - AMQP_USER
 * - AMQP_PASSWORD
 * - AMQP_VHOST
 * 
 * Reference: https://www.rabbitmq.com/docs/ae
 */
class AlternateExchangeIntegrationTest extends IntegrationTestBase
{
    protected $alternateExchange;
    protected $alternateQueue;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Use fixed names for alternate exchange tests
        $this->testQueueName = 'test-queue-alternate';
        $this->testExchange = 'test-exchange-alternate';
        $this->testRoutingKey = 'test.routing.key';
        $this->alternateExchange = 'unroutable-exchange';
        $this->alternateQueue = 'unroutable-queue';
        
        // Delete queues and exchanges if they exist to start fresh
        $this->deleteQueue($this->testQueueName);
        $this->deleteQueue($this->alternateQueue);
        $this->deleteExchange($this->testExchange);
        $this->deleteExchange($this->alternateExchange);
        
        // Update config with test queue and exchange
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['routing'] = $this->testRoutingKey;
        $this->configRepository->set('amqp', $config);
    }

    protected function tearDown(): void
    {
        // Clean up: delete test queues and exchanges
        $this->deleteQueue($this->testQueueName);
        $this->deleteQueue($this->alternateQueue);
        $this->deleteExchange($this->testExchange);
        $this->deleteExchange($this->alternateExchange);
        parent::tearDown();
    }

    /**
     * Test that an exchange can be declared with alternate-exchange
     */
    public function testExchangeDeclareWithAlternateExchange()
    {
        // Delete main exchange first to ensure clean state
        $this->deleteExchange($this->testExchange);
        
        // First, create the alternate exchange and queue
        $this->setupAlternateExchange();

        // Now create the main exchange with alternate-exchange property
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['exchange_type'] = 'topic'; // Explicitly set exchange type
        $config['properties']['test']['exchange_properties'] = [
            'alternate-exchange' => $this->alternateExchange
        ];
        $config['properties']['test']['exchange_force_declare'] = true;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Verify exchange was declared successfully
        $channel = $publisher->getChannel();
        try {
            // Use passive declaration with all required parameters
            $channel->exchange_declare(
                $this->testExchange,
                'topic', // exchange type
                true,    // passive
                false,   // durable
                false,   // auto_delete
                false,   // internal
                false,   // nowait
                null     // arguments
            );
            $this->assertTrue(true); // If no exception, exchange exists
        } catch (\Exception $e) {
            $this->fail("Exchange should exist: " . $e->getMessage());
        }
        
        Request::shutdown($channel, $publisher->getConnection());
    }

    /**
     * Test that unroutable messages are sent to alternate exchange
     */
    public function testUnroutableMessageSentToAlternateExchange()
    {
        // Delete main exchange first
        $this->deleteExchange($this->testExchange);
        
        // Setup alternate exchange and queue
        $this->setupAlternateExchange();

        // Create main exchange with alternate-exchange
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['exchange_type'] = 'topic';
        $config['properties']['test']['exchange_properties'] = [
            'alternate-exchange' => $this->alternateExchange
        ];
        $config['properties']['test']['exchange_force_declare'] = true;
        
        $this->configRepository->set('amqp', $config);

        // Publish a message with a routing key that doesn't match any queue
        $testMessage = 'Unroutable message';
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $publisher->publish('nonexistent.routing.key', $message); // Routing key that doesn't match any queue
        
        // Small delay to ensure message is routed
        usleep(200000); // 0.2 seconds
        
        // Consume from alternate queue
        $consumedMessages = [];
        $alternateConfig = $this->configRepository->get('amqp');
        $alternateConfig['properties']['test']['queue'] = $this->alternateQueue;
        $alternateConfig['properties']['test']['exchange'] = $this->alternateExchange;
        $alternateConfig['properties']['test']['routing'] = '#'; // Consume all messages
        $this->configRepository->set('amqp', $alternateConfig);
        
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $consumer->consume(
            $this->alternateQueue,
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
        
        // Verify message was routed to alternate exchange
        $this->assertCount(1, $consumedMessages);
        $this->assertEquals($testMessage, $consumedMessages[0]);
        
        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Test that routable messages are NOT sent to alternate exchange
     */
    public function testRoutableMessageNotSentToAlternateExchange()
    {
        // Delete main exchange and queue first
        $this->deleteExchange($this->testExchange);
        $this->deleteQueue($this->testQueueName);
        
        // Setup alternate exchange and queue
        $this->setupAlternateExchange();

        // Create main exchange with alternate-exchange
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['exchange_type'] = 'topic';
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['routing'] = $this->testRoutingKey;
        $config['properties']['test']['exchange_properties'] = [
            'alternate-exchange' => $this->alternateExchange
        ];
        $config['properties']['test']['exchange_force_declare'] = true;
        $config['properties']['test']['queue_force_declare'] = true;
        
        $this->configRepository->set('amqp', $config);

        // Publish a message with a routing key that matches the queue
        $testMessage = 'Routable message';
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $publisher->publish($this->testRoutingKey, $message);
        
        // Small delay
        usleep(200000); // 0.2 seconds
        
        // Consume from main queue (should have the message)
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
        
        // Verify message was in main queue, not alternate
        $this->assertCount(1, $consumedMessages);
        $this->assertEquals($testMessage, $consumedMessages[0]);
        
        // Verify alternate queue is empty
        $alternateConfig = $this->configRepository->get('amqp');
        $alternateConfig['properties']['test']['queue'] = $this->alternateQueue;
        $alternateConfig['properties']['test']['exchange'] = $this->alternateExchange;
        $alternateConfig['properties']['test']['routing'] = '#';
        $this->configRepository->set('amqp', $alternateConfig);
        
        $alternateConsumer = new Consumer($this->configRepository);
        $alternateConsumer->setup();
        
        $alternateMessages = [];
        try {
            $alternateConsumer->consume(
                $this->alternateQueue,
                function ($msg, $resolver) use (&$alternateMessages) {
                    $alternateMessages[] = $msg->body;
                    $resolver->acknowledge($msg);
                    $resolver->stopWhenProcessed();
                },
                [
                    'timeout' => 1,
                    'persistent' => true
                ]
            );
        } catch (AMQPTimeoutException $e) {
            // Timeout is expected if queue is empty
        }
        
        $this->assertCount(0, $alternateMessages, 'Routable message should not be in alternate queue');
        
        Request::shutdown($alternateConsumer->getChannel(), $alternateConsumer->getConnection());
        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Setup alternate exchange and queue for testing
     */
    private function setupAlternateExchange(): void
    {
        // Delete alternate exchange first to ensure clean state
        $this->deleteExchange($this->alternateExchange);
        $this->deleteQueue($this->alternateQueue);
        
        // Create alternate exchange (fanout type is common for alternate exchanges)
        // Note: Alternate exchange should NOT have alternate-exchange property itself
        $alternateConfig = $this->configRepository->get('amqp');
        $alternateConfig['properties']['test']['exchange'] = $this->alternateExchange;
        $alternateConfig['properties']['test']['exchange_type'] = 'fanout';
        $alternateConfig['properties']['test']['exchange_properties'] = []; // No alternate-exchange on alternate exchange
        $alternateConfig['properties']['test']['exchange_force_declare'] = true;
        $alternateConfig['properties']['test']['queue'] = $this->alternateQueue;
        $alternateConfig['properties']['test']['routing'] = '';
        $alternateConfig['properties']['test']['queue_force_declare'] = true;
        
        $this->configRepository->set('amqp', $alternateConfig);
        
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
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

    /**
     * Helper method to delete an exchange if it exists
     */
    private function deleteExchange(string $exchangeName): void
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
            $channel->exchange_delete($exchangeName, false);
            Request::shutdown($channel, $request->getConnection());
        } catch (\Exception $e) {
            // Exchange might not exist, ignore error
        }
    }
}

