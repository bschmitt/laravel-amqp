<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Models\Message;
use Bschmitt\Amqp\Support\AsyncPublisher;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Mockery as m;

class AsyncPublisherTest extends BaseTestCase
{
    public function testPublishingUsesConfirmsAndStatsTrackPending(): void
    {
        $factory = m::mock(PublisherFactoryInterface::class);
        $publisher = m::mock(Publisher::class);

        $publisher->shouldReceive('enablePublisherConfirms')->once();
        $publisher->shouldReceive('setAckHandler')->once();
        $publisher->shouldReceive('setNackHandler')->once();
        $publisher->shouldReceive('publish')
            ->twice()
            ->andReturn(true);
        $publisher->shouldReceive('waitForConfirms')
            ->once()
            ->andReturn(true);
        $publisher->shouldReceive('getConnectionManager')->andReturn(null);

        $factory->shouldReceive('create')->once()->andReturn($publisher);

        $messageFactory = new MessageFactory();
        $async = new AsyncPublisher($factory, $messageFactory, ['exchange' => 'events']);

        $async->publish('orders.created', 'one');
        $async->publish('orders.created', 'two');

        $stats = $async->stats();
        $this->assertSame(2, $stats['published']);
        $this->assertSame(2, $stats['pending']);

        $this->assertTrue($async->flush());
        $stats = $async->stats();
        $this->assertSame(0, $stats['pending']);
    }

    public function testFlushReturnsTrueWhenNothingPublished(): void
    {
        $factory = m::mock(PublisherFactoryInterface::class);
        $async = new AsyncPublisher($factory, new MessageFactory());
        $this->assertTrue($async->flush());
    }
}
