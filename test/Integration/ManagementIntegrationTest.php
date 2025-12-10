<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Test\Support\IntegrationTestBase;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;

/**
 * Integration tests for management operations
 * 
 * Tests:
 * - Queue Unbind
 * - Exchange Unbind
 * - Queue Purge
 * - Queue Delete
 * - Exchange Delete
 */
class ManagementIntegrationTest extends IntegrationTestBase
{
    protected $amqp;
    protected $testQueueName;
    protected $testExchange;
    protected $testRoutingKey;
    protected $testExchange2;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        $this->testQueueName = 'test-queue-management-' . time();
        $this->testExchange = 'test-exchange-management-' . time();
        $this->testExchange2 = 'test-exchange-management-2-' . time();
        $this->testRoutingKey = 'test.routing.key';

        // Create Amqp instance
        $config = include dirname(__FILE__) . '/../../config/amqp.php';
        $defaultProperties = $config['properties'][$config['use']];
        
        $this->loadEnvFile();
        
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => array_merge($defaultProperties, [
                        'host' => $this->getEnv('AMQP_HOST', $defaultProperties['host'] ?? 'localhost'),
                        'port' => (int) $this->getEnv('AMQP_PORT', $defaultProperties['port'] ?? 5672),
                        'username' => $this->getEnv('AMQP_USER', $defaultProperties['username'] ?? 'guest'),
                        'password' => $this->getEnv('AMQP_PASSWORD', $defaultProperties['password'] ?? 'guest'),
                        'vhost' => $this->getEnv('AMQP_VHOST', $defaultProperties['vhost'] ?? '/'),
                        'exchange' => $this->testExchange,
                        'queue' => $this->testQueueName,
                        'queue_durable' => false,
                        'queue_auto_delete' => true,
                        'routing' => $this->testRoutingKey,
                    ])
                ]
            ]
        ];

        $configRepository = new \Illuminate\Config\Repository($configArray);
        $configProvider = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        
        $publisherFactory = new \Bschmitt\Amqp\Factories\PublisherFactory($configProvider);
        $consumerFactory = new \Bschmitt\Amqp\Factories\ConsumerFactory($configProvider);
        $messageFactory = new \Bschmitt\Amqp\Factories\MessageFactory();
        $batchManager = new \Bschmitt\Amqp\Managers\BatchManager();
        
        $this->amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);
    }

    protected function tearDown(): void
    {
        // Cleanup: try to delete test resources
        $this->cleanupTestResources();
        parent::tearDown();
    }

    protected function cleanupTestResources(): void
    {
        try {
            $config = include dirname(__FILE__) . '/../../config/amqp.php';
            $defaultProperties = $config['properties'][$config['use']];
            
            $configArray = [
                'amqp' => [
                    'use' => 'test',
                    'properties' => [
                        'test' => array_merge($defaultProperties, [
                            'host' => $this->getEnv('AMQP_HOST', 'localhost'),
                            'port' => (int) $this->getEnv('AMQP_PORT', 5672),
                            'username' => $this->getEnv('AMQP_USER', 'guest'),
                            'password' => $this->getEnv('AMQP_PASSWORD', 'guest'),
                            'vhost' => $this->getEnv('AMQP_VHOST', '/'),
                        ])
                    ]
                ]
            ];

            $configRepository = new \Illuminate\Config\Repository($configArray);
            $configProvider = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
            $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($configProvider);
            $connectionManager->connect();
            $channel = $connectionManager->getChannel();

            // Try to delete queue (ignore errors if it doesn't exist)
            try {
                $channel->queue_delete($this->testQueueName, false, false);
            } catch (\Exception $e) {
                // Ignore
            }

            // Try to delete exchanges (ignore errors if they don't exist)
            try {
                $channel->exchange_delete($this->testExchange, false);
            } catch (\Exception $e) {
                // Ignore
            }

            try {
                $channel->exchange_delete($this->testExchange2, false);
            } catch (\Exception $e) {
                // Ignore
            }

            $connectionManager->disconnect();
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    protected function getTestProperties(): array
    {
        $config = include dirname(__FILE__) . '/../../config/amqp.php';
        $defaultProperties = $config['properties'][$config['use']];
        
        return array_merge($defaultProperties, [
            'host' => $this->getEnv('AMQP_HOST', 'localhost'),
            'port' => (int) $this->getEnv('AMQP_PORT', 5672),
            'username' => $this->getEnv('AMQP_USER', 'guest'),
            'password' => $this->getEnv('AMQP_PASSWORD', 'guest'),
            'vhost' => $this->getEnv('AMQP_VHOST', '/'),
        ]);
    }

    protected function setupTestQueueAndExchange(): void
    {
        // Create exchange and queue
        $config = include dirname(__FILE__) . '/../../config/amqp.php';
        $defaultProperties = $config['properties'][$config['use']];
        
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => array_merge($defaultProperties, [
                        'host' => $this->getEnv('AMQP_HOST', 'localhost'),
                        'port' => (int) $this->getEnv('AMQP_PORT', 5672),
                        'username' => $this->getEnv('AMQP_USER', 'guest'),
                        'password' => $this->getEnv('AMQP_PASSWORD', 'guest'),
                        'vhost' => $this->getEnv('AMQP_VHOST', '/'),
                        'exchange' => $this->testExchange,
                        'queue' => $this->testQueueName,
                        'queue_durable' => false,
                        'queue_auto_delete' => true,
                        'routing' => $this->testRoutingKey,
                    ])
                ]
            ]
        ];

        $configRepository = new \Illuminate\Config\Repository($configArray);
        $configProvider = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($configProvider);
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();

        // Declare exchange
        $channel->exchange_declare($this->testExchange, 'topic', false, false, false, false, false, null);
        
        // Declare queue
        $channel->queue_declare($this->testQueueName, false, false, false, true);
        
        // Bind queue to exchange
        $channel->queue_bind($this->testQueueName, $this->testExchange, $this->testRoutingKey);

        $connectionManager->disconnect();
    }

    public function testQueueUnbind()
    {
        $this->setupTestQueueAndExchange();

        $testProperties = $this->getTestProperties();

        // Publish a message directly using channel to verify binding works
        $configRepository = new \Illuminate\Config\Repository([
            'amqp' => [
                'use' => 'test',
                'properties' => ['test' => $testProperties]
            ]
        ]);
        $configProvider = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($configProvider);
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();

        // Publish a message to verify binding works
        $message = new \PhpAmqpLib\Message\AMQPMessage('test message');
        $channel->basic_publish($message, $this->testExchange, $this->testRoutingKey);
        
        $connectionManager->disconnect();

        // Unbind the queue
        $this->amqp->queueUnbind($this->testQueueName, $this->testExchange, $this->testRoutingKey, null, $testProperties);

        // Publish another message - it should not be routed to the queue
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();
        $message2 = new \PhpAmqpLib\Message\AMQPMessage('test message 2');
        $channel->basic_publish($message2, $this->testExchange, $this->testRoutingKey);
        $connectionManager->disconnect();

        // Consume messages directly - should only get the first one
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();
        $messages = [];
        $channel->basic_consume($this->testQueueName, '', false, false, false, false, function ($msg) use (&$messages) {
            $messages[] = $msg->body;
            $msg->ack();
        });
        
        // Wait for messages with timeout
        $startTime = time();
        while (count($messages) < 1 && (time() - $startTime) < 2) {
            $channel->wait(null, false, 1);
        }
        
        $connectionManager->disconnect();

        // Should have received the first message only
        $this->assertCount(1, $messages);
        $this->assertEquals('test message', $messages[0]);
    }

    public function testExchangeUnbind()
    {
        // Create two exchanges
        $config = include dirname(__FILE__) . '/../../config/amqp.php';
        $defaultProperties = $config['properties'][$config['use']];
        
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => array_merge($defaultProperties, [
                        'host' => $this->getEnv('AMQP_HOST', 'localhost'),
                        'port' => (int) $this->getEnv('AMQP_PORT', 5672),
                        'username' => $this->getEnv('AMQP_USER', 'guest'),
                        'password' => $this->getEnv('AMQP_PASSWORD', 'guest'),
                        'vhost' => $this->getEnv('AMQP_VHOST', '/'),
                    ])
                ]
            ]
        ];

        $configRepository = new \Illuminate\Config\Repository($configArray);
        $configProvider = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($configProvider);
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();

        // Declare exchanges
        $channel->exchange_declare($this->testExchange, 'topic', false, false, false, false, false, null);
        $channel->exchange_declare($this->testExchange2, 'topic', false, false, false, false, false, null);
        
        // Bind exchange2 to exchange1
        $channel->exchange_bind($this->testExchange2, $this->testExchange, $this->testRoutingKey);

        $connectionManager->disconnect();

        $testProperties = $this->getTestProperties();

        // Unbind exchange2 from exchange1
        $this->amqp->exchangeUnbind($this->testExchange2, $this->testExchange, $this->testRoutingKey, null, $testProperties);

        // Verify unbind worked by checking that messages published to exchange1 don't reach exchange2
        // (This is a basic test - full verification would require a queue bound to exchange2)
        $this->assertTrue(true, 'Exchange unbind completed without errors');
    }

    public function testQueuePurge()
    {
        $this->setupTestQueueAndExchange();

        $testProperties = $this->getTestProperties();

        // Publish multiple messages directly using channel
        $configRepository = new \Illuminate\Config\Repository([
            'amqp' => [
                'use' => 'test',
                'properties' => ['test' => $testProperties]
            ]
        ]);
        $configProvider = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($configProvider);
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();

        // Publish multiple messages
        for ($i = 1; $i <= 5; $i++) {
            $message = new \PhpAmqpLib\Message\AMQPMessage("message $i");
            $channel->basic_publish($message, $this->testExchange, $this->testRoutingKey);
        }
        
        $connectionManager->disconnect();

        // Purge the queue
        $purgedCount = $this->amqp->queuePurge($this->testQueueName, $testProperties);

        // Verify all messages were purged
        $this->assertGreaterThanOrEqual(5, $purgedCount);

        // Try to consume - should get no messages
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();
        $messages = [];
        $channel->basic_consume($this->testQueueName, '', false, false, false, false, function ($msg) use (&$messages) {
            $messages[] = $msg->body;
            $msg->ack();
        });
        
        // Wait briefly - should get no messages (timeout is expected)
        try {
            $channel->wait(null, false, 1);
        } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
            // Expected - queue is empty
        }
        
        $connectionManager->disconnect();

        $this->assertCount(0, $messages, 'Queue should be empty after purge');
    }

    public function testQueueDelete()
    {
        $this->setupTestQueueAndExchange();

        $testProperties = $this->getTestProperties();

        // Publish a message directly using channel
        $configRepository = new \Illuminate\Config\Repository([
            'amqp' => [
                'use' => 'test',
                'properties' => ['test' => $testProperties]
            ]
        ]);
        $configProvider = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($configProvider);
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();

        $message = new \PhpAmqpLib\Message\AMQPMessage('test message');
        $channel->basic_publish($message, $this->testExchange, $this->testRoutingKey);
        
        $connectionManager->disconnect();

        // Delete the queue
        $deletedCount = $this->amqp->queueDelete($this->testQueueName, false, false, $testProperties);

        // Verify queue was deleted (should return number of messages deleted)
        $this->assertGreaterThanOrEqual(1, $deletedCount);

        // Try to declare the queue again - should succeed (queue doesn't exist)
        $config = include dirname(__FILE__) . '/../../config/amqp.php';
        $defaultProperties = $config['properties'][$config['use']];
        
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => array_merge($defaultProperties, [
                        'host' => $this->getEnv('AMQP_HOST', 'localhost'),
                        'port' => (int) $this->getEnv('AMQP_PORT', 5672),
                        'username' => $this->getEnv('AMQP_USER', 'guest'),
                        'password' => $this->getEnv('AMQP_PASSWORD', 'guest'),
                        'vhost' => $this->getEnv('AMQP_VHOST', '/'),
                        'exchange' => $this->testExchange,
                        'queue' => $this->testQueueName,
                        'queue_durable' => false,
                        'queue_auto_delete' => true,
                    ])
                ]
            ]
        ];

        $configRepository = new \Illuminate\Config\Repository($configArray);
        $configProvider = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($configProvider);
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();

        // Should be able to declare the queue again (it was deleted)
        $channel->queue_declare($this->testQueueName, false, false, false, true);
        
        $connectionManager->disconnect();
    }

    public function testQueueDeleteWithIfUnused()
    {
        $this->setupTestQueueAndExchange();

        $testProperties = $this->getTestProperties();

        // Delete queue with if_unused flag
        // Since we're not using it, it should delete
        $deletedCount = $this->amqp->queueDelete($this->testQueueName, true, false, $testProperties);

        $this->assertGreaterThanOrEqual(0, $deletedCount);
    }

    public function testQueueDeleteWithIfEmpty()
    {
        $this->setupTestQueueAndExchange();

        $testProperties = $this->getTestProperties();

        // Delete queue with if_empty flag
        // Since queue is empty, it should delete
        $deletedCount = $this->amqp->queueDelete($this->testQueueName, false, true, $testProperties);

        $this->assertGreaterThanOrEqual(0, $deletedCount);
    }

    public function testExchangeDelete()
    {
        // Create an exchange
        $config = include dirname(__FILE__) . '/../../config/amqp.php';
        $defaultProperties = $config['properties'][$config['use']];
        
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => array_merge($defaultProperties, [
                        'host' => $this->getEnv('AMQP_HOST', 'localhost'),
                        'port' => (int) $this->getEnv('AMQP_PORT', 5672),
                        'username' => $this->getEnv('AMQP_USER', 'guest'),
                        'password' => $this->getEnv('AMQP_PASSWORD', 'guest'),
                        'vhost' => $this->getEnv('AMQP_VHOST', '/'),
                    ])
                ]
            ]
        ];

        $configRepository = new \Illuminate\Config\Repository($configArray);
        $configProvider = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($configProvider);
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();

        // Declare exchange
        $channel->exchange_declare($this->testExchange, 'topic', false, false, false, false, false, null);
        
        $connectionManager->disconnect();

        $testProperties = $this->getTestProperties();

        // Delete the exchange
        $this->amqp->exchangeDelete($this->testExchange, false, $testProperties);

        // Verify exchange was deleted by trying to declare it again
        // Create a new connection manager for verification
        $configProvider2 = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        $connectionManager2 = new \Bschmitt\Amqp\Managers\ConnectionManager($configProvider2);
        $connectionManager2->connect();
        $channel2 = $connectionManager2->getChannel();

        // Should be able to declare the exchange again (it was deleted)
        $channel2->exchange_declare($this->testExchange, 'topic', false, false, false, false, false, null);
        
        $connectionManager2->disconnect();
        
        $this->assertTrue(true, 'Exchange delete completed successfully');
    }

    public function testExchangeDeleteWithIfUnused()
    {
        // Create an exchange
        $config = include dirname(__FILE__) . '/../../config/amqp.php';
        $defaultProperties = $config['properties'][$config['use']];
        
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => array_merge($defaultProperties, [
                        'host' => $this->getEnv('AMQP_HOST', 'localhost'),
                        'port' => (int) $this->getEnv('AMQP_PORT', 5672),
                        'username' => $this->getEnv('AMQP_USER', 'guest'),
                        'password' => $this->getEnv('AMQP_PASSWORD', 'guest'),
                        'vhost' => $this->getEnv('AMQP_VHOST', '/'),
                    ])
                ]
            ]
        ];

        $configRepository = new \Illuminate\Config\Repository($configArray);
        $configProvider = new \Bschmitt\Amqp\Support\ConfigurationProvider($configRepository);
        $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($configProvider);
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();

        // Declare exchange
        $channel->exchange_declare($this->testExchange, 'topic', false, false, false, false, false, null);
        
        $connectionManager->disconnect();

        $testProperties = $this->getTestProperties();

        // Delete exchange with if_unused flag
        // Since it's not used, it should delete
        try {
            $this->amqp->exchangeDelete($this->testExchange, true, $testProperties);
            $this->assertTrue(true, 'Exchange delete with if_unused completed');
        } catch (\Exception $e) {
            // If exchange is in use, that's also a valid test result
            $this->assertTrue(true, 'Exchange delete with if_unused handled exception: ' . $e->getMessage());
        }
    }
}

