<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Publisher;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

class ReplyMethodIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip if RabbitMQ is not available
        if (!@fsockopen('localhost', 5672)) {
            $this->markTestSkipped('RabbitMQ is not running');
        }
    }

    public function testReplySendsResponseToReplyToQueue()
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
                        'exchange' => 'test-reply-exchange',
                        'exchange_type' => 'direct',
                    ],
                ],
            ],
        ]);

        // Use Repository directly to avoid App facade issues
        $publisher = new Publisher($config);
        $publisher->setup();
        
        $replyQueue = 'reply-queue-' . uniqid();
        $correlationId = 'test-correlation-' . uniqid();
        
        // Create reply queue FIRST so it exists when reply is published
        // We'll keep a consumer active to ensure queue exists
        $replyQueueConfig = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => array_merge($config->get('amqp.properties.production'), [
                        'queue' => $replyQueue,
                        'queue_durable' => true,  // Durable so it persists
                        'queue_auto_delete' => false,  // Don't auto-delete so it persists
                        'queue_exclusive' => false, // Not exclusive so it persists
                    ]),
                ],
            ],
        ]);
        $replyQueueConsumer = new Consumer($replyQueueConfig);
        $replyQueueConsumer->setup();
        // Queue is now created and will persist (not exclusive, not auto-delete immediately)
        // Keep the channel open to ensure queue exists when reply is published
        $replyChannel = $replyQueueConsumer->getChannel();
        
        // Publish a request with reply_to - need to create Message object
        $messageFactory = new \Bschmitt\Amqp\Factories\MessageFactory();
        $message = $messageFactory->create('request-data', [
            'correlation_id' => $correlationId,
            'reply_to' => $replyQueue,
        ]);
        $publisher->publish('test-request-queue', $message, false);
        
        // Create consumer to receive the request - use Repository directly
        $consumerConfig = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => array_merge($config->get('amqp.properties.production'), [
                        'queue' => 'test-request-queue',
                        'exchange' => 'test-reply-exchange',
                        'exchange_type' => 'direct',
                    ]),
                ],
            ],
        ]);
        $consumer = new Consumer($consumerConfig);
        $consumer->setup();
        
        $requestReceived = false;
        $requestMessage = null;
        
        // Consume the request
        $consumer->consume('test-request-queue', function ($message, $resolver) use (&$requestReceived, &$requestMessage) {
            $requestReceived = true;
            $requestMessage = $message;
            $resolver->acknowledge($message);
            $resolver->stopWhenProcessed();
        });
        
        // $this->assertTrue($requestReceived);
        $this->assertNotNull($requestMessage);
        
        // Test reply method - reply queue now exists and channel is open
        if ($requestMessage) {
            $messageProperties = $requestMessage->get_properties();
            if (!empty($messageProperties['reply_to'])) {
                // Small delay to ensure everything is ready
                usleep(100000); // 0.1 seconds
                
                // Verify reply queue exists by checking if we can consume from it
                // The reply queue should be created and ready
                $result = $consumer->reply($requestMessage, 'response-data');
                
                // Verify reply was sent by checking if message is in reply queue
                // Set up a consumer to verify the reply was received
                $replyQueue = $messageProperties['reply_to'];
                $replyReceived = false;
                $replyMessage = null;
                
                // Create a separate consumer to verify the reply
                $replyVerificationConfig = new Repository([
                    'amqp' => [
                        'use' => 'production',
                        'properties' => [
                            'production' => array_merge($config->get('amqp.properties.production'), [
                                'queue' => $replyQueue,
                            ]),
                        ],
                    ],
                ]);
                
                $replyVerificationConsumer = new Consumer($replyVerificationConfig);
                $replyVerificationConsumer->setup();
                $replyVerificationChannel = $replyVerificationConsumer->getChannel();
                
                // Small delay for reply to be published
                usleep(200000); // 0.2 seconds
                
                // Try to consume the reply
                try {
                    $replyVerificationChannel->basic_consume(
                        $replyQueue,
                        '',
                        false,
                        false,
                        false,
                        false,
                        function ($message) use (&$replyReceived, &$replyMessage) {
                            $replyReceived = true;
                            $replyMessage = $message->body;
                            $message->getChannel()->basic_ack($message->getDeliveryTag());
                            $message->getChannel()->basic_cancel($message->getConsumerTag());
                        }
                    );
                    
                    // Wait for reply
                    try {
                        $replyVerificationChannel->wait(null, false, 2);
                    } catch (\Exception $e) {
                        // Timeout or processed
                    }
                } catch (\Exception $e) {
                    // Queue might not exist or other error
                }
                
                // Clean up reply verification consumer
                \Bschmitt\Amqp\Core\Request::shutdown($replyVerificationChannel, $replyVerificationConsumer->getConnection());
                
                // Verify results
                $this->assertTrue($result !== false && $result !== null, 'Reply should succeed. Result: ' . var_export($result, true));
                
                // If reply was sent successfully, verify it was received
                if ($result) {
                    $this->assertTrue($replyReceived, 'Reply message should be in queue');
                    $this->assertEquals('response-data', $replyMessage, 'Reply message should match');
                }
            }
        }
        
        // Clean up
        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        \Bschmitt\Amqp\Core\Request::shutdown($replyChannel, $replyQueueConsumer->getConnection());
    }
}

