<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Test\Support\IntegrationTestBase;

/**
 * Integration tests for message properties
 * 
 * Tests:
 * - Message Priority
 * - Message Headers (full manipulation)
 * - Message Correlation ID
 * - Message Reply-To
 */
class MessagePropertiesIntegrationTest extends IntegrationTestBase
{
    protected $amqp;
    protected $testQueueName;
    protected $testExchange;
    protected $testRoutingKey;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        $this->testQueueName = 'test-queue-message-props-' . time();
        $this->testExchange = 'test-exchange-message-props-' . time();
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
                        'queue_properties' => [
                            'x-max-priority' => 10 // Enable priority support
                        ],
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
        // Cleanup
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

            try {
                $channel->queue_delete($this->testQueueName, false, false);
            } catch (\Exception $e) {
                // Ignore
            }

            try {
                $channel->exchange_delete($this->testExchange, false);
            } catch (\Exception $e) {
                // Ignore
            }

            $connectionManager->disconnect();
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    protected function setupTestQueueAndExchange(): void
    {
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
                        'queue_properties' => [
                            'x-max-priority' => 10
                        ],
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
        
        // Declare queue with priority support
        $queueProperties = new \PhpAmqpLib\Wire\AMQPTable(['x-max-priority' => 10]);
        $channel->queue_declare($this->testQueueName, false, false, false, true, false, $queueProperties);
        
        // Bind queue to exchange
        $channel->queue_bind($this->testQueueName, $this->testExchange, $this->testRoutingKey);

        $connectionManager->disconnect();
    }

    public function testMessagePriority()
    {
        $this->setupTestQueueAndExchange();

        $testProperties = $this->getTestProperties();

        // Publish message with priority directly using channel
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

        $message = new \PhpAmqpLib\Message\AMQPMessage('priority message', [
            'priority' => 5,
            'delivery_mode' => 2
        ]);
        $channel->basic_publish($message, $this->testExchange, $this->testRoutingKey);
        $connectionManager->disconnect();

        // Consume and verify priority directly using channel
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();
        $receivedMessage = null;
        
        $channel->basic_consume($this->testQueueName, '', false, false, false, false, function ($msg) use (&$receivedMessage) {
            $receivedMessage = $msg;
            $msg->ack();
        });
        
        $startTime = time();
        while ($receivedMessage === null && (time() - $startTime) < 2) {
            $channel->wait(null, false, 1);
        }
        $connectionManager->disconnect();

        $this->assertNotNull($receivedMessage, 'Message should be received');
        $properties = $receivedMessage->get_properties();
        $this->assertEquals(5, $properties['priority'] ?? null);
    }

    public function testMessageCorrelationId()
    {
        $this->setupTestQueueAndExchange();

        $testProperties = $this->getTestProperties();
        $correlationId = 'test-correlation-' . time();

        // Publish message with correlation ID directly using channel
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

        $message = new \PhpAmqpLib\Message\AMQPMessage('correlation message', [
            'correlation_id' => $correlationId,
            'delivery_mode' => 2
        ]);
        $channel->basic_publish($message, $this->testExchange, $this->testRoutingKey);
        $connectionManager->disconnect();

        // Consume and verify correlation ID directly using channel
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();
        $receivedMessage = null;
        
        $channel->basic_consume($this->testQueueName, '', false, false, false, false, function ($msg) use (&$receivedMessage) {
            $receivedMessage = $msg;
            $msg->ack();
        });
        
        $startTime = time();
        while ($receivedMessage === null && (time() - $startTime) < 2) {
            $channel->wait(null, false, 1);
        }
        $connectionManager->disconnect();

        $this->assertNotNull($receivedMessage);
        $properties = $receivedMessage->get_properties();
        $this->assertEquals($correlationId, $properties['correlation_id'] ?? null);
    }

    public function testMessageReplyTo()
    {
        $this->setupTestQueueAndExchange();

        $testProperties = $this->getTestProperties();
        $replyTo = 'reply-queue-' . time();

        // Publish message with reply-to directly using channel
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

        $message = new \PhpAmqpLib\Message\AMQPMessage('reply message', [
            'reply_to' => $replyTo,
            'delivery_mode' => 2
        ]);
        $channel->basic_publish($message, $this->testExchange, $this->testRoutingKey);
        $connectionManager->disconnect();

        // Consume and verify reply-to directly using channel
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();
        $receivedMessage = null;
        
        $channel->basic_consume($this->testQueueName, '', false, false, false, false, function ($msg) use (&$receivedMessage) {
            $receivedMessage = $msg;
            $msg->ack();
        });
        
        $startTime = time();
        while ($receivedMessage === null && (time() - $startTime) < 2) {
            $channel->wait(null, false, 1);
        }
        $connectionManager->disconnect();

        $this->assertNotNull($receivedMessage);
        $properties = $receivedMessage->get_properties();
        $this->assertEquals($replyTo, $properties['reply_to'] ?? null);
    }

    public function testMessageHeaders()
    {
        $this->setupTestQueueAndExchange();

        $testProperties = $this->getTestProperties();

        // Publish message with custom headers directly using channel
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

        $headers = new \PhpAmqpLib\Wire\AMQPTable([
            'x-custom-header' => 'custom-value',
            'x-another-header' => 'another-value',
            'x-number' => 123
        ]);

        $message = new \PhpAmqpLib\Message\AMQPMessage('header message', [
            'application_headers' => $headers,
            'delivery_mode' => 2
        ]);
        $channel->basic_publish($message, $this->testExchange, $this->testRoutingKey);
        $connectionManager->disconnect();

        // Consume and verify headers directly using channel
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();
        $receivedMessage = null;
        
        $channel->basic_consume($this->testQueueName, '', false, false, false, false, function ($msg) use (&$receivedMessage) {
            $receivedMessage = $msg;
            $msg->ack();
        });
        
        $startTime = time();
        while ($receivedMessage === null && (time() - $startTime) < 2) {
            $channel->wait(null, false, 1);
        }
        $connectionManager->disconnect();

        $this->assertNotNull($receivedMessage);
        $properties = $receivedMessage->get_properties();
        
        $this->assertArrayHasKey('application_headers', $properties);
        $this->assertInstanceOf(\PhpAmqpLib\Wire\AMQPTable::class, $properties['application_headers']);
        
        $receivedHeaders = $properties['application_headers']->getNativeData();
        $this->assertEquals('custom-value', $receivedHeaders['x-custom-header']);
        $this->assertEquals('another-value', $receivedHeaders['x-another-header']);
        $this->assertEquals(123, $receivedHeaders['x-number']);
    }

    public function testMessageAllPropertiesTogether()
    {
        $this->setupTestQueueAndExchange();

        $testProperties = $this->getTestProperties();
        $correlationId = 'corr-' . time();
        $replyTo = 'reply-' . time();

        // Publish message with all properties directly using channel
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

        $headers = new \PhpAmqpLib\Wire\AMQPTable([
            'x-test' => 'test-value'
        ]);

        $message = new \PhpAmqpLib\Message\AMQPMessage('full properties message', [
            'priority' => 7,
            'correlation_id' => $correlationId,
            'reply_to' => $replyTo,
            'application_headers' => $headers,
            'delivery_mode' => 2
        ]);
        $channel->basic_publish($message, $this->testExchange, $this->testRoutingKey);
        $connectionManager->disconnect();

        // Consume and verify all properties directly using channel
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();
        $receivedMessage = null;
        
        $channel->basic_consume($this->testQueueName, '', false, false, false, false, function ($msg) use (&$receivedMessage) {
            $receivedMessage = $msg;
            $msg->ack();
        });
        
        $startTime = time();
        while ($receivedMessage === null && (time() - $startTime) < 2) {
            $channel->wait(null, false, 1);
        }
        $connectionManager->disconnect();

        $this->assertNotNull($receivedMessage);
        $properties = $receivedMessage->get_properties();
        
        $this->assertEquals(7, $properties['priority'] ?? null);
        $this->assertEquals($correlationId, $properties['correlation_id'] ?? null);
        $this->assertEquals($replyTo, $properties['reply_to'] ?? null);
        
        $receivedHeaders = $properties['application_headers']->getNativeData();
        $this->assertEquals('test-value', $receivedHeaders['x-test']);
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
}

