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
 * Integration tests for all exchange types
 * 
 * These tests require a real RabbitMQ instance running.
 * Configure connection details in .env file:
 * - AMQP_HOST
 * - AMQP_PORT
 * - AMQP_USER
 * - AMQP_PASSWORD
 * - AMQP_VHOST
 * 
 * Tests all RabbitMQ exchange types:
 * - topic: Pattern-based routing
 * - direct: Exact match routing
 * - fanout: Broadcast to all queues
 * - headers: Header-based routing
 * 
 * Reference: https://www.rabbitmq.com/docs/exchanges
 */
class ExchangeTypeIntegrationTest extends IntegrationTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Use fixed names for exchange type tests
        $this->testQueueName = 'test-queue-exchange-type';
        $this->testExchange = 'test-exchange-type';
        $this->testRoutingKey = 'test.routing.key';
        
        // Delete queue and exchange if they exist to start fresh
        $this->deleteQueue($this->testQueueName);
        $this->deleteExchange($this->testExchange);
    }

    protected function tearDown(): void
    {
        // Clean up: delete test queue and exchange
        if ($this->testQueueName !== null) {
            $this->deleteQueue($this->testQueueName);
        }
        if ($this->testExchange !== null) {
            $this->deleteExchange($this->testExchange);
        }
        parent::tearDown();
    }

    /**
     * Test topic exchange type
     */
    public function testTopicExchangeType()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['exchange_type'] = 'topic';
        $config['properties']['test']['exchange_force_declare'] = true;
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['routing'] = $this->testRoutingKey;
        $config['properties']['test']['queue_force_declare'] = true;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Verify exchange was declared successfully
        $channel = $publisher->getChannel();
        try {
            $channel->exchange_declare(
                $this->testExchange,
                'topic',
                true, // passive
                false,
                false,
                false,
                false,
                null
            );
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->fail("Topic exchange should exist: " . $e->getMessage());
        }
        
        Request::shutdown($channel, $publisher->getConnection());
    }

    /**
     * Test direct exchange type
     */
    public function testDirectExchangeType()
    {
        $this->deleteExchange($this->testExchange);
        
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['exchange_type'] = 'direct';
        $config['properties']['test']['exchange_force_declare'] = true;
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['routing'] = $this->testRoutingKey;
        $config['properties']['test']['queue_force_declare'] = true;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Verify exchange was declared successfully
        $channel = $publisher->getChannel();
        try {
            $channel->exchange_declare(
                $this->testExchange,
                'direct',
                true, // passive
                false,
                false,
                false,
                false,
                null
            );
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->fail("Direct exchange should exist: " . $e->getMessage());
        }
        
        Request::shutdown($channel, $publisher->getConnection());
    }

    /**
     * Test fanout exchange type
     */
    public function testFanoutExchangeType()
    {
        $this->deleteExchange($this->testExchange);
        
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['exchange_type'] = 'fanout';
        $config['properties']['test']['exchange_force_declare'] = true;
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['routing'] = ''; // Fanout ignores routing key
        $config['properties']['test']['queue_force_declare'] = true;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Verify exchange was declared successfully
        $channel = $publisher->getChannel();
        try {
            $channel->exchange_declare(
                $this->testExchange,
                'fanout',
                true, // passive
                false,
                false,
                false,
                false,
                null
            );
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->fail("Fanout exchange should exist: " . $e->getMessage());
        }
        
        Request::shutdown($channel, $publisher->getConnection());
    }

    /**
     * Test headers exchange type
     */
    public function testHeadersExchangeType()
    {
        $this->deleteExchange($this->testExchange);
        
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['exchange_type'] = 'headers';
        $config['properties']['test']['exchange_force_declare'] = true;
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['routing'] = ''; // Headers uses message headers, not routing key
        $config['properties']['test']['queue_force_declare'] = true;
        
        $this->configRepository->set('amqp', $config);

        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Verify exchange was declared successfully
        $channel = $publisher->getChannel();
        try {
            $channel->exchange_declare(
                $this->testExchange,
                'headers',
                true, // passive
                false,
                false,
                false,
                false,
                null
            );
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->fail("Headers exchange should exist: " . $e->getMessage());
        }
        
        Request::shutdown($channel, $publisher->getConnection());
    }

    /**
     * Test publish and consume with topic exchange
     */
    public function testPublishAndConsumeWithTopicExchange()
    {
        $this->deleteExchange($this->testExchange);
        $this->deleteQueue($this->testQueueName);
        
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['exchange_type'] = 'topic';
        $config['properties']['test']['exchange_force_declare'] = true;
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['routing'] = $this->testRoutingKey;
        $config['properties']['test']['queue_force_declare'] = true;
        
        $this->configRepository->set('amqp', $config);

        // Publish a message
        $testMessage = 'Topic exchange test message';
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $publisher->publish($this->testRoutingKey, $message);
        
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
     * Test publish and consume with fanout exchange
     */
    public function testPublishAndConsumeWithFanoutExchange()
    {
        $this->deleteExchange($this->testExchange);
        $this->deleteQueue($this->testQueueName);
        
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['exchange'] = $this->testExchange;
        $config['properties']['test']['exchange_type'] = 'fanout';
        $config['properties']['test']['exchange_force_declare'] = true;
        $config['properties']['test']['queue'] = $this->testQueueName;
        $config['properties']['test']['routing'] = ''; // Fanout ignores routing key
        $config['properties']['test']['queue_force_declare'] = true;
        
        $this->configRepository->set('amqp', $config);

        // Publish a message
        $testMessage = 'Fanout exchange test message';
        $message = new Message($testMessage, ['content_type' => 'text/plain']);
        
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $publisher->publish('any.routing.key', $message); // Routing key is ignored by fanout
        
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
        
        // Verify message was consumed (fanout broadcasts to all bound queues)
        $this->assertCount(1, $consumedMessages);
        $this->assertEquals($testMessage, $consumedMessages[0]);
        
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

