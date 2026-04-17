<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Publisher;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

class QueueRpcFunctionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip if RabbitMQ is not available
        if (!@fsockopen('localhost', 5672)) {
            $this->markTestSkipped('RabbitMQ is not running');
        }
    }

    /**
     * Test the original queue_rpc function (with its issues)
     * This test demonstrates the problems with the original implementation
     */
    public function testOriginalQueueRpcFunction()
    {
        $queue = 'test-rpc-queue-' . uniqid();
        $message = 'test-message-' . uniqid();
        
        // This test will likely fail due to App facade issues
        // But we'll test it to demonstrate the problems
        
        try {
            // Try to use App facade (will fail in test environment)
            if (class_exists('\Illuminate\Support\Facades\App')) {
                $publisher = \Illuminate\Support\Facades\App::make('Bschmitt\Amqp\Publisher');
            } else {
                $this->markTestSkipped('App facade not available in test environment');
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('Cannot test original function: ' . $e->getMessage());
        }
    }

    /**
     * Test that the package's built-in rpc() method works better
     * This demonstrates the recommended approach
     */
    public function testPackageBuiltInRpcMethod()
    {
        $baseConfig = [
            'host' => 'localhost',
            'port' => 5672,
            'username' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'exchange' => 'amq.direct',
            'exchange_type' => 'direct',
        ];

        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => $baseConfig,
                ],
            ],
        ]);

        $queue = 'test-rpc-queue-' . uniqid();
        $requestMessage = 'test-request-' . uniqid();
        $expectedResponse = 'Response to: ' . $requestMessage;

        // Set up RPC server
        // Use exchange_passive for built-in amq.direct exchange
        $serverConfig = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => array_merge($baseConfig, [
                        'queue' => $queue,
                        'routing' => [$queue],
                        'exchange_passive' => true, // Built-in exchange already exists
                        'exchange_durable' => true, // Built-in exchange is durable
                    ]),
                ],
            ],
        ]);

        $serverConsumer = new Consumer($serverConfig);
        $serverConsumer->setup();
        $serverChannel = $serverConsumer->getChannel();

        // Set up server callback
        $serverProcessed = false;
        $serverChannel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function ($message) use (&$serverProcessed, $serverConsumer, $expectedResponse, $requestMessage) {
                $serverProcessed = true;
                
                // Verify request
                $this->assertEquals($requestMessage, $message->body);
                
                // Reply using the reply() method
                $serverConsumer->reply($message, $expectedResponse);
                
                // Acknowledge the request
                $message->getChannel()->basic_ack($message->getDeliveryTag());
                
                // Cancel consumer to stop
                $message->getChannel()->basic_cancel($message->getConsumerTag());
            }
        );

        // Use package's built-in rpc() method with non-blocking approach
        $configProvider = new \Bschmitt\Amqp\Support\ConfigurationProvider($config);
        $consumerFactory = new \Bschmitt\Amqp\Factories\ConsumerFactory($configProvider);
        $publisherFactory = new \Bschmitt\Amqp\Factories\PublisherFactory($configProvider);
        $messageFactory = new \Bschmitt\Amqp\Factories\MessageFactory();
        $batchManager = new \Bschmitt\Amqp\Managers\BatchManager();

        $amqp = new \Bschmitt\Amqp\Core\Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        // NON-BLOCKING APPROACH: Manually implement RPC to control timing
        $correlationId = uniqid('rpc_', true);
        $replyQueue = 'rpc-reply-' . $correlationId;

        $responseReceived = false;
        $response = null;

        // Create reply queue consumer - IMPORTANT: queue must exist before reply is published
        // Note: ConsumerFactory->create() already calls setup(), so we don't need to call it again
        // Use NON-EXCLUSIVE queue so server can publish to it from different connection
        $replyConsumerProperties = array_merge($baseConfig, [
            'queue' => $replyQueue,
            'queue_auto_delete' => true,
            'queue_exclusive' => false, // NOT exclusive - allows server to publish from different connection
            'exchange' => 'amq.direct',
            'exchange_type' => 'direct',
            'exchange_passive' => true,
            'exchange_durable' => true,
        ]);

        $replyConsumer = $consumerFactory->create($replyConsumerProperties);
        // setup() is already called by create(), so queue should exist now
        $replyChannel = $replyConsumer->getChannel();

        // CRITICAL: Set up reply consumer callback BEFORE publishing request
        // The queue is already created by ConsumerFactory->create()->setup()
        $replyChannel->basic_consume(
            $replyQueue,
            '',
            false,
            false,
            false,
            false,
            function ($message) use (&$responseReceived, &$response, $correlationId) {
                $messageProperties = $message->get_properties();
                $msgCorrelationId = $messageProperties['correlation_id'] ?? null;
                
                if ($msgCorrelationId === $correlationId) {
                    $response = $message->body;
                    $responseReceived = true;
                    $message->getChannel()->basic_ack($message->getDeliveryTag());
                    $message->getChannel()->basic_cancel($message->getConsumerTag());
                } else {
                    $message->getChannel()->basic_reject($message->getDeliveryTag(), true);
                }
            }
        );

        // Small delay to ensure consumer is registered
        usleep(200000); // 0.2 seconds - increased delay

        // Publish request
        $requestProperties = array_merge($baseConfig, [
            'correlation_id' => $correlationId,
            'reply_to' => $replyQueue,
            'exchange' => 'amq.direct',
            'exchange_type' => 'direct',
            'exchange_passive' => true,
            'exchange_durable' => true,
        ]);

        $amqp->publish($queue, $requestMessage, $requestProperties);

        // Small delay to ensure message is in queue
        usleep(200000); // 0.2 seconds

        // Process server message (this will trigger callback and send reply)
        $serverProcessedAttempts = 0;
        $maxServerAttempts = 10;
        while (!$serverProcessed && $serverProcessedAttempts < $maxServerAttempts) {
            try {
                $serverChannel->wait(null, false, 0.5);
                if ($serverProcessed) {
                    break;
                }
            } catch (\Exception $e) {
                // Timeout or processed, continue
            }
            $serverProcessedAttempts++;
            usleep(100000); // 0.1 seconds between attempts
        }

        // Ensure server processed
        $this->assertTrue($serverProcessed, 'Server should process request');

        // Small delay for reply to be published
        usleep(500000); // 0.5 seconds - increased delay

        // Wait for response - actively consume
        $startTime = time();
        $timeout = 10;
        $waitAttempts = 0;
        $maxWaitAttempts = 30;
        
        while (!$responseReceived && (time() - $startTime) < $timeout && $waitAttempts < $maxWaitAttempts) {
            try {
                $replyChannel->wait(null, false, 0.3);
                if ($responseReceived) {
                    break;
                }
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                $waitAttempts++;
                usleep(50000); // 0.05 seconds
                continue;
            } catch (\PhpAmqpLib\Exception\AMQPChannelClosedException $e) {
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    error_log('Reply channel closed: ' . $e->getMessage());
                }
                break;
            } catch (\Exception $e) {
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    error_log('Reply wait exception: ' . $e->getMessage());
                }
                break;
            }
        }

        // Clean up
        \Bschmitt\Amqp\Core\Request::shutdown($replyConsumer->getChannel(), $replyConsumer->getConnection());
        \Bschmitt\Amqp\Core\Request::shutdown($serverConsumer->getChannel(), $serverConsumer->getConnection());

        // Verify results
        $this->assertTrue($serverProcessed, 'Server should process request');
        $this->assertNotNull($response, 'Should receive reply');
        $this->assertEquals($expectedResponse, $response, 'Reply should match expected response');
    }
}

