<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Factories\ConsumerFactory;
use Bschmitt\Amqp\Factories\PublisherFactory;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Managers\BatchManager;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\App;
use Mockery;

class ConnectionConfigTest extends \PHPUnit\Framework\TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testGetConnectionConfigReturnsConfigFromRepository()
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => [
                        'host' => 'localhost',
                        'port' => 5672,
                    ],
                    'analytics' => [
                        'host' => 'analytics-host',
                        'port' => 5672,
                    ],
                ],
            ],
        ]);

        App::shouldReceive('make')
            ->with('config')
            ->andReturn($config);

        $consumerFactory = Mockery::mock(ConsumerFactory::class);
        $publisherFactory = Mockery::mock(PublisherFactory::class);
        $messageFactory = new MessageFactory();
        $batchManager = Mockery::mock(BatchManager::class);

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $connectionConfig = $amqp->getConnectionConfig('analytics');

        $this->assertEquals('analytics-host', $connectionConfig['host']);
        $this->assertEquals(5672, $connectionConfig['port']);
    }

    public function testGetConnectionConfigThrowsExceptionForMissingConnection()
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => [],
                ],
            ],
        ]);

        App::shouldReceive('make')
            ->with('config')
            ->andReturn($config);

        $consumerFactory = Mockery::mock(ConsumerFactory::class);
        $publisherFactory = Mockery::mock(PublisherFactory::class);
        $messageFactory = new MessageFactory();
        $batchManager = Mockery::mock(BatchManager::class);

        $amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Connection 'missing' not found in config");

        $amqp->getConnectionConfig('missing');
    }
}

