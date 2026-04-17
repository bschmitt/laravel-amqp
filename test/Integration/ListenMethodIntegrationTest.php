<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Factories\ConsumerFactory;
use Bschmitt\Amqp\Factories\PublisherFactory;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Managers\BatchManager;
use Bschmitt\Amqp\Support\ConfigurationProvider;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

class ListenMethodIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip if RabbitMQ is not available
        if (!@fsockopen('localhost', 5672)) {
            $this->markTestSkipped('RabbitMQ is not running');
        }
    }

    public function testListenReceivesMessagesFromMultipleRoutingKeys()
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => [
                        'host' => 'localhost',
                        'port' => 5672,
                        'username' => 'guest',
                        'password' => 'guest',
                        'vhost' => '/',
                        'exchange' => 'test-listen-exchange',
                        'exchange_type' => 'topic',
                    ],
                ],
            ],
        ]);

        $configProvider = new ConfigurationProvider($config);
        $consumerFactory = new ConsumerFactory($configProvider);
        $publisherFactory = new PublisherFactory($configProvider);
        $messageFactory = new MessageFactory();
        $batchManager = new BatchManager();

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $baseConfig = $config->get('amqp.properties.production');
        
        // Generate a queue name and pass it explicitly to listen()
        // This allows us to create the queue beforehand
        $queueName = 'listener-' . uniqid('', true);
        $listenProperties = array_merge($baseConfig, [
            'queue' => $queueName,  // Explicit queue name
            'exchange' => 'test-listen-exchange',
            'exchange_type' => 'topic',
            'timeout' => 5,
        ]);
        
        // Create queue and bind it BEFORE publishing messages
        // This ensures messages are routed to the queue
        // Use same properties that listen() will use (auto_delete=true is default)
        $tempConsumer = $consumerFactory->create(array_merge($baseConfig, [
            'queue' => $queueName,
            'exchange' => 'test-listen-exchange',
            'exchange_type' => 'topic',
            'routing' => ['test.key1', 'test.key2'],
            'queue_auto_delete' => true,  // Match listen() default
        ]));
        $tempConsumer->setup(); // This creates the queue and binds it
        \Bschmitt\Amqp\Core\Request::shutdown($tempConsumer->getChannel(), $tempConsumer->getConnection());
        
        // Now publish messages - queue exists and is bound
        $amqp->publish('test.key1', 'Message 1', array_merge($baseConfig, [
            'exchange' => 'test-listen-exchange',
            'exchange_type' => 'topic',
        ]));

        $amqp->publish('test.key2', 'Message 2', array_merge($baseConfig, [
            'exchange' => 'test-listen-exchange',
            'exchange_type' => 'topic',
        ]));
        
        // Small delay to ensure messages are in queue
        usleep(100000); // 0.1 seconds

        // Now listen to both routing keys - queue already exists and has messages
        $receivedMessages = [];
        $amqp->listen(['test.key1', 'test.key2'], function ($message, $resolver) use (&$receivedMessages) {
            $receivedMessages[] = $message->body;
            $resolver->acknowledge($message);
            if (count($receivedMessages) >= 2) {
                $resolver->stopWhenProcessed();
            }
        }, $listenProperties);

        $this->assertCount(2, $receivedMessages);
        $this->assertContains('Message 1', $receivedMessages);
        $this->assertContains('Message 2', $receivedMessages);
    }

    public function testListenWithStringRoutingKeys()
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => [
                        'host' => 'localhost',
                        'port' => 5672,
                        'username' => 'guest',
                        'password' => 'guest',
                        'vhost' => '/',
                        'exchange' => 'test-listen-string-exchange',
                        'exchange_type' => 'topic',
                    ],
                ],
            ],
        ]);

        $configProvider = new ConfigurationProvider($config);
        $consumerFactory = new ConsumerFactory($configProvider);
        $publisherFactory = new PublisherFactory($configProvider);
        $messageFactory = new MessageFactory();
        $batchManager = new BatchManager();

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $baseConfig = $config->get('amqp.properties.production');
        
        // Generate a queue name and pass it explicitly to listen()
        $queueName = 'listener-' . uniqid('', true);
        $listenProperties = array_merge($baseConfig, [
            'queue' => $queueName,  // Explicit queue name
            'exchange' => 'test-listen-string-exchange',
            'exchange_type' => 'topic',
            'timeout' => 5,
        ]);
        
        // Create queue and bind it BEFORE publishing message
        // Use same properties that listen() will use (auto_delete=true is default)
        $tempConsumer = $consumerFactory->create(array_merge($baseConfig, [
            'queue' => $queueName,
            'exchange' => 'test-listen-string-exchange',
            'exchange_type' => 'topic',
            'routing' => ['test.key'],
            'queue_auto_delete' => true,  // Match listen() default
        ]));
        $tempConsumer->setup(); // This creates the queue and binds it
        \Bschmitt\Amqp\Core\Request::shutdown($tempConsumer->getChannel(), $tempConsumer->getConnection());
        
        // Publish a message - queue exists and is bound
        $amqp->publish('test.key', 'Test Message', array_merge($baseConfig, [
            'exchange' => 'test-listen-string-exchange',
            'exchange_type' => 'topic',
        ]));
        
        // Small delay to ensure message is in queue
        usleep(100000); // 0.1 seconds

        // Listen using comma-separated string - queue already exists and has message
        $received = null;
        $amqp->listen('test.key', function ($message, $resolver) use (&$received) {
            $received = $message->body;
            $resolver->acknowledge($message);
            $resolver->stopWhenProcessed();
        }, $listenProperties);

        $this->assertEquals('Test Message', $received);
    }
}

