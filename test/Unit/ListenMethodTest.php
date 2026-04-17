<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Factories\ConsumerFactory;
use Bschmitt\Amqp\Factories\PublisherFactory;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Managers\BatchManager;
use Illuminate\Config\Repository;
use Mockery;

class ListenMethodTest extends \PHPUnit\Framework\TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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
                        'exchange' => 'test-exchange',
                        'exchange_type' => 'topic',
                    ],
                ],
            ],
        ]);

        $consumerFactory = Mockery::mock(ConsumerFactory::class);
        $publisherFactory = Mockery::mock(PublisherFactory::class);
        $messageFactory = new MessageFactory();
        $batchManager = Mockery::mock(BatchManager::class);

        $consumer = Mockery::mock(Consumer::class);
        $consumer->shouldReceive('consume')
            ->once()
            ->with(
                Mockery::pattern('/^listener-/'),
                Mockery::type('Closure')
            )
            ->andReturn(true);
        $channel = Mockery::mock(\PhpAmqpLib\Channel\AMQPChannel::class);
        $channel->shouldReceive('close')->andReturn(true);
        $connection = Mockery::mock(\PhpAmqpLib\Connection\AMQPStreamConnection::class);
        $connection->shouldReceive('close')->andReturn(true);
        
        $consumer->shouldReceive('getConnectionManager')
            ->andReturn(null);
        $consumer->shouldReceive('getChannel')
            ->andReturn($channel);
        $consumer->shouldReceive('getConnection')
            ->andReturn($connection);

        $consumerFactory->shouldReceive('create')
            ->once()
            ->andReturn($consumer);

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $callback = function ($message, $resolver) {
            // Test callback
        };

        $result = $amqp->listen('routing.key,other.key', $callback, [
            'exchange' => 'test-exchange',
        ]);

        $this->assertTrue($result);
    }

    public function testListenWithArrayRoutingKeys()
    {
        $consumerFactory = Mockery::mock(ConsumerFactory::class);
        $publisherFactory = Mockery::mock(PublisherFactory::class);
        $messageFactory = new MessageFactory();
        $batchManager = Mockery::mock(BatchManager::class);

        $consumer = Mockery::mock(Consumer::class);
        $consumer->shouldReceive('consume')
            ->once()
            ->with(
                'custom-queue',
                Mockery::type('Closure')
            )
            ->andReturn(true);
        $channel = Mockery::mock(\PhpAmqpLib\Channel\AMQPChannel::class);
        $channel->shouldReceive('close')->andReturn(true);
        $connection = Mockery::mock(\PhpAmqpLib\Connection\AMQPStreamConnection::class);
        $connection->shouldReceive('close')->andReturn(true);
        
        $consumer->shouldReceive('getConnectionManager')
            ->andReturn(null);
        $consumer->shouldReceive('getChannel')
            ->andReturn($channel);
        $consumer->shouldReceive('getConnection')
            ->andReturn($connection);

        $consumerFactory->shouldReceive('create')
            ->once()
            ->andReturn($consumer);

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $callback = function ($message, $resolver) {
            // Test callback
        };

        $result = $amqp->listen(['key1', 'key2', 'key3'], $callback, [
            'queue' => 'custom-queue',
            'exchange' => 'test-exchange',
        ]);

        $this->assertTrue($result);
    }

    public function testListenThrowsExceptionForEmptyRoutingKeys()
    {
        $consumerFactory = Mockery::mock(ConsumerFactory::class);
        $publisherFactory = Mockery::mock(PublisherFactory::class);
        $messageFactory = new MessageFactory();
        $batchManager = Mockery::mock(BatchManager::class);

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Routing keys must be a non-empty string or array');

        $amqp->listen('', function () {});
    }

    public function testListenAutoGeneratesQueueName()
    {
        $consumerFactory = Mockery::mock(ConsumerFactory::class);
        $publisherFactory = Mockery::mock(PublisherFactory::class);
        $messageFactory = new MessageFactory();
        $batchManager = Mockery::mock(BatchManager::class);

        $consumer = Mockery::mock(Consumer::class);
        $consumer->shouldReceive('consume')
            ->once()
            ->with(
                Mockery::pattern('/^listener-/'),
                Mockery::type('Closure')
            )
            ->andReturn(true);
        $channel = Mockery::mock(\PhpAmqpLib\Channel\AMQPChannel::class);
        $channel->shouldReceive('close')->andReturn(true);
        $connection = Mockery::mock(\PhpAmqpLib\Connection\AMQPStreamConnection::class);
        $connection->shouldReceive('close')->andReturn(true);
        
        $consumer->shouldReceive('getConnectionManager')
            ->andReturn(null);
        $consumer->shouldReceive('getChannel')
            ->andReturn($channel);
        $consumer->shouldReceive('getConnection')
            ->andReturn($connection);

        $consumerFactory->shouldReceive('create')
            ->once()
            ->andReturn($consumer);

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $result = $amqp->listen('test.key', function () {}, [
            'exchange' => 'test-exchange',
        ]);

        $this->assertTrue($result);
    }

    public function testListenSetsDefaultExchangeType()
    {
        $consumerFactory = Mockery::mock(ConsumerFactory::class);
        $publisherFactory = Mockery::mock(PublisherFactory::class);
        $messageFactory = new MessageFactory();
        $batchManager = Mockery::mock(BatchManager::class);

        $consumer = Mockery::mock(Consumer::class);
        $consumer->shouldReceive('consume')
            ->once()
            ->with(
                Mockery::pattern('/^listener-/'),
                Mockery::type('Closure')
            )
            ->andReturn(true);
        $channel = Mockery::mock(\PhpAmqpLib\Channel\AMQPChannel::class);
        $channel->shouldReceive('close')->andReturn(true);
        $connection = Mockery::mock(\PhpAmqpLib\Connection\AMQPStreamConnection::class);
        $connection->shouldReceive('close')->andReturn(true);
        
        $consumer->shouldReceive('getConnectionManager')
            ->andReturn(null);
        $consumer->shouldReceive('getChannel')
            ->andReturn($channel);
        $consumer->shouldReceive('getConnection')
            ->andReturn($connection);

        $consumerFactory->shouldReceive('create')
            ->once()
            ->andReturn($consumer);

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $result = $amqp->listen('test.key', function () {}, [
            'exchange' => 'test-exchange',
            // exchange_type not specified, should default to 'topic'
        ]);

        $this->assertTrue($result);
    }
}

