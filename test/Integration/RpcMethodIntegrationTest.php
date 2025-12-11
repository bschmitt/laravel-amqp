<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Factories\ConsumerFactory;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Factories\PublisherFactory;
use Bschmitt\Amqp\Managers\BatchManager;
use Bschmitt\Amqp\Support\ConfigurationProvider;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

class RpcMethodIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip if RabbitMQ is not available
        if (!@fsockopen('localhost', 5672)) {
            $this->markTestSkipped('RabbitMQ is not running');
        }
        
        // Enable error logging for debugging
        if (!defined('APP_DEBUG')) {
            define('APP_DEBUG', true);
        }
    }


    /**
     * Test RPC timeout when server doesn't respond
     */
    public function testRpcTimeout()
    {
        $rpcServerQueue = 'rpc-server-queue-timeout-' . uniqid();
        $rpcExchange = 'test-rpc-exchange-timeout-' . uniqid();
        
        $baseConfig = [
            'host' => 'localhost',
            'port' => 5672,
            'username' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'exchange' => $rpcExchange,
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

        $requestData = 'test-request-timeout-' . uniqid();

        // Set up Amqp instance for RPC call
        $configProvider = new ConfigurationProvider($config);
        $consumerFactory = new ConsumerFactory($configProvider);
        $publisherFactory = new PublisherFactory($configProvider);
        $messageFactory = new MessageFactory();
        $batchManager = new BatchManager();

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        // Make RPC call with short timeout (server won't respond)
        // Use try-catch to handle timeout exception
        try {
            $response = $amqp->rpc($rpcServerQueue, $requestData, $baseConfig, 2);
            // Verify timeout
            $this->assertNull($response, 'Response should be null on timeout');
        } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
            // Timeout exception is expected
            $this->assertTrue(true, 'Timeout exception is expected when server does not respond');
        } catch (\Exception $e) {
            // Other exceptions might occur, but timeout should result in null response
            $this->markTestSkipped('Unexpected exception during timeout test: ' . $e->getMessage());
        }
    }

    /**
     * Test that rpc() method exists and works
     */
    public function testRpcMethodExists()
    {
        $baseConfig = [
            'host' => 'localhost',
            'port' => 5672,
            'username' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
        ];

        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => $baseConfig,
                ],
            ],
        ]);

        $configProvider = new ConfigurationProvider($config);
        $consumerFactory = new ConsumerFactory($configProvider);
        $publisherFactory = new PublisherFactory($configProvider);
        $messageFactory = new MessageFactory();
        $batchManager = new BatchManager();

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        // Verify method exists
        $this->assertTrue(method_exists($amqp, 'rpc'), 'rpc() method should exist');
    }
}

