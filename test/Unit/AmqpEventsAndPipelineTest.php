<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Events\MessageHandled;
use Bschmitt\Amqp\Events\MessagePublished;
use Bschmitt\Amqp\Events\MessagePublishing;
use Bschmitt\Amqp\Events\MessageReceived;
use Bschmitt\Amqp\Support\EventDispatcher;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Mockery as m;
use PhpAmqpLib\Message\AMQPMessage;

class AmqpEventsAndPipelineTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        EventDispatcher::instance()->flushListeners();
        parent::tearDown();
    }

    public function testPublishDispatchesPublishingAndPublishedEvents(): void
    {
        $factory = m::mock(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class);
        $consumerFactory = m::mock(\Bschmitt\Amqp\Contracts\ConsumerFactoryInterface::class);
        $messageFactory = m::mock(\Bschmitt\Amqp\Factories\MessageFactory::class);
        $batch = m::mock(\Bschmitt\Amqp\Contracts\BatchManagerInterface::class);

        $publisher = m::mock(\Bschmitt\Amqp\Contracts\PublisherInterface::class);
        $publisher->shouldReceive('publish')->once()->andReturn(true);

        $factory->shouldReceive('create')->once()->andReturn($publisher);
        $messageFactory->shouldReceive('create')->once()->andReturn(m::mock(\Bschmitt\Amqp\Models\Message::class));

        $events = [];
        EventDispatcher::instance()->listen(MessagePublishing::class, function ($e) use (&$events) {
            $events[] = 'publishing';
        });
        EventDispatcher::instance()->listen(MessagePublished::class, function ($e) use (&$events) {
            $events[] = 'published';
        });

        $amqp = new Amqp($factory, $consumerFactory, $messageFactory, $batch);
        $amqp->publish('routing.key', 'body');

        $this->assertSame(['publishing', 'published'], $events);
    }

    public function testConsumeWithMiddlewareRunsPipelineAndEvents(): void
    {
        $factory = m::mock(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class);
        $consumerFactory = m::mock(\Bschmitt\Amqp\Contracts\ConsumerFactoryInterface::class);
        $messageFactory = m::mock(\Bschmitt\Amqp\Factories\MessageFactory::class);
        $batch = m::mock(\Bschmitt\Amqp\Contracts\BatchManagerInterface::class);

        $consumer = m::mock(\Bschmitt\Amqp\Contracts\ConsumerInterface::class);
        $consumer->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback) {
                $callback(new AMQPMessage('payload'), null);
                return true;
            });
        $consumerFactory->shouldReceive('create')->once()->andReturn($consumer);

        $order = [];
        EventDispatcher::instance()->listen(MessageReceived::class, function () use (&$order) {
            $order[] = 'received';
        });
        EventDispatcher::instance()->listen(MessageHandled::class, function () use (&$order) {
            $order[] = 'handled';
        });

        $amqp = new Amqp($factory, $consumerFactory, $messageFactory, $batch);

        $amqp->consumeWithMiddleware('orders', function ($message, $resolver) use (&$order) {
            $order[] = 'handler';
        }, [
            function ($message, $next) use (&$order) {
                $order[] = 'mw-before';
                $next($message);
                $order[] = 'mw-after';
            },
        ]);

        $this->assertSame(['mw-before', 'received', 'handler', 'handled', 'mw-after'], $order);
    }
}
