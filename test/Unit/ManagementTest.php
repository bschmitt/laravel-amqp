<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Managers\Management;
use Bschmitt\Amqp\Managers\ConnectionManager;
use Bschmitt\Amqp\Support\ConfigurationProvider;
use Illuminate\Config\Repository;
use Mockery as m;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;

class ManagementTest extends TestCase
{
    protected $configRepository;
    protected $config;
    protected $connectionManager;
    protected $channelMock;
    protected $management;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configRepository = m::mock(Repository::class);
        $this->configRepository->shouldReceive('has')
            ->with('amqp')
            ->andReturn(false);
        
        $this->config = new ConfigurationProvider($this->configRepository);
        $this->connectionManager = m::mock(ConnectionManager::class);
        $this->channelMock = m::mock(AMQPChannel::class);
        
        $this->management = new Management($this->config, $this->connectionManager);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function testQueueUnbind()
    {
        $queue = 'test-queue';
        $exchange = 'test-exchange';
        $routingKey = 'test.routing.key';

        $this->connectionManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($this->channelMock);

        $this->channelMock->shouldReceive('queue_unbind')
            ->once()
            ->with($queue, $exchange, $routingKey, null);

        $this->management->queueUnbind($queue, $exchange, $routingKey);
        
        $this->assertTrue(true, 'Queue unbind called successfully');
    }

    public function testQueueUnbindWithArguments()
    {
        $queue = 'test-queue';
        $exchange = 'test-exchange';
        $routingKey = 'test.routing.key';
        $arguments = ['x-match' => 'all'];

        $this->connectionManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($this->channelMock);

        $this->channelMock->shouldReceive('queue_unbind')
            ->once()
            ->with($queue, $exchange, $routingKey, m::type(AMQPTable::class));

        $this->management->queueUnbind($queue, $exchange, $routingKey, $arguments);
        
        $this->assertTrue(true, 'Queue unbind with arguments called successfully');
    }

    public function testQueueUnbindWithEmptyRoutingKey()
    {
        $queue = 'test-queue';
        $exchange = 'test-exchange';

        $this->connectionManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($this->channelMock);

        $this->channelMock->shouldReceive('queue_unbind')
            ->once()
            ->with($queue, $exchange, '', null);

        $this->management->queueUnbind($queue, $exchange);
        
        $this->assertTrue(true, 'Queue unbind with empty routing key called successfully');
    }

    public function testExchangeUnbind()
    {
        $destination = 'dest-exchange';
        $source = 'source-exchange';
        $routingKey = 'test.routing.key';

        $this->connectionManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($this->channelMock);

        $this->channelMock->shouldReceive('exchange_unbind')
            ->once()
            ->with($destination, $source, $routingKey, false, null);

        $this->management->exchangeUnbind($destination, $source, $routingKey);
        
        $this->assertTrue(true, 'Exchange unbind called successfully');
    }

    public function testExchangeUnbindWithArguments()
    {
        $destination = 'dest-exchange';
        $source = 'source-exchange';
        $routingKey = 'test.routing.key';
        $arguments = ['x-match' => 'all'];

        $this->connectionManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($this->channelMock);

        $this->channelMock->shouldReceive('exchange_unbind')
            ->once()
            ->with($destination, $source, $routingKey, false, m::type(AMQPTable::class));

        $this->management->exchangeUnbind($destination, $source, $routingKey, $arguments);
        
        $this->assertTrue(true, 'Exchange unbind with arguments called successfully');
    }

    public function testQueuePurge()
    {
        $queue = 'test-queue';
        $purgedCount = 5;

        $this->connectionManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($this->channelMock);

        $this->channelMock->shouldReceive('queue_purge')
            ->once()
            ->with($queue)
            ->andReturn($purgedCount);

        $result = $this->management->queuePurge($queue);
        
        $this->assertEquals($purgedCount, $result);
    }

    public function testQueueDelete()
    {
        $queue = 'test-queue';
        $deletedCount = 10;

        $this->connectionManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($this->channelMock);

        $this->channelMock->shouldReceive('queue_delete')
            ->once()
            ->with($queue, false, false)
            ->andReturn($deletedCount);

        $result = $this->management->queueDelete($queue);
        
        $this->assertEquals($deletedCount, $result);
    }

    public function testQueueDeleteWithIfUnused()
    {
        $queue = 'test-queue';
        $deletedCount = 0;

        $this->connectionManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($this->channelMock);

        $this->channelMock->shouldReceive('queue_delete')
            ->once()
            ->with($queue, true, false)
            ->andReturn($deletedCount);

        $result = $this->management->queueDelete($queue, true);
        
        $this->assertEquals($deletedCount, $result);
    }

    public function testQueueDeleteWithIfEmpty()
    {
        $queue = 'test-queue';
        $deletedCount = 0;

        $this->connectionManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($this->channelMock);

        $this->channelMock->shouldReceive('queue_delete')
            ->once()
            ->with($queue, false, true)
            ->andReturn($deletedCount);

        $result = $this->management->queueDelete($queue, false, true);
        
        $this->assertEquals($deletedCount, $result);
    }

    public function testExchangeDelete()
    {
        $exchange = 'test-exchange';

        $this->connectionManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($this->channelMock);

        $this->channelMock->shouldReceive('exchange_delete')
            ->once()
            ->with($exchange, false);

        $this->management->exchangeDelete($exchange);
        
        $this->assertTrue(true, 'Exchange delete called successfully');
    }

    public function testExchangeDeleteWithIfUnused()
    {
        $exchange = 'test-exchange';

        $this->connectionManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($this->channelMock);

        $this->channelMock->shouldReceive('exchange_delete')
            ->once()
            ->with($exchange, true);

        $this->management->exchangeDelete($exchange, true);
        
        $this->assertTrue(true, 'Exchange delete with if_unused called successfully');
    }

    public function testGetConnectionManager()
    {
        $result = $this->management->getConnectionManager();
        
        $this->assertSame($this->connectionManager, $result);
    }
}

