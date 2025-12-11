<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Factories\ConsumerFactory;
use Bschmitt\Amqp\Factories\PublisherFactory;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Managers\BatchManager;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Mockery;

class RpcMethodTest extends \PHPUnit\Framework\TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testRpcMethodExists()
    {
        // This test verifies that RPC method exists and has correct signature
        // Full RPC functionality is tested in RpcMethodIntegrationTest with real RabbitMQ
        $consumerFactory = Mockery::mock(ConsumerFactory::class);
        $publisherFactory = Mockery::mock(PublisherFactory::class);
        $messageFactory = new MessageFactory();
        $batchManager = Mockery::mock(BatchManager::class);

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        // Verify the method exists
        $this->assertTrue(method_exists($amqp, 'rpc'), 'rpc() method should exist');
        
        // Verify method signature
        $reflection = new \ReflectionMethod($amqp, 'rpc');
        $this->assertEquals(4, $reflection->getNumberOfParameters(), 'rpc() should have 4 parameters');
        
        // Verify parameter types
        $params = $reflection->getParameters();
        $this->assertEquals('routingKey', $params[0]->getName());
        $this->assertEquals('request', $params[1]->getName());
        $this->assertEquals('properties', $params[2]->getName());
        $this->assertEquals('timeout', $params[3]->getName());
        $this->assertEquals(30, $params[3]->getDefaultValue(), 'Default timeout should be 30 seconds');
        
        // Note: Full RPC functionality with blocking behavior and async message handling
        // is tested in RpcMethodIntegrationTest with real RabbitMQ connections
    } 
}

