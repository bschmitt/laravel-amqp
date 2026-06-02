<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\InteropMessage;
use Bschmitt\Amqp\Support\MetricsCollector;
use Bschmitt\Amqp\Support\QueueMetrics;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Mockery as m;

class AmqpScaleFeaturesTest extends BaseTestCase
{
    public function testPublishInteropAppliesHeadersAndPublishes(): void
    {
        $factory = m::mock(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class);
        $consumerFactory = m::mock(\Bschmitt\Amqp\Contracts\ConsumerFactoryInterface::class);
        $messageFactory = m::mock(\Bschmitt\Amqp\Factories\MessageFactory::class);
        $batch = m::mock(\Bschmitt\Amqp\Contracts\BatchManagerInterface::class);

        $publisher = m::mock(\Bschmitt\Amqp\Contracts\PublisherInterface::class);
        $publisher->shouldReceive('publish')->once()->andReturn(true);

        $factory->shouldReceive('create')->once()->andReturn($publisher);
        $messageFactory->shouldReceive('create')->once()->andReturn(m::mock(\Bschmitt\Amqp\Models\Message::class));

        $amqp = new Amqp($factory, $consumerFactory, $messageFactory, $batch);
        $amqp->publishInterop('orders.created', ['id' => 1], 'orders.created', 'shop');

        $this->assertSame(1, $amqp->metrics()->snapshot()['published']);
    }

    public function testQueueMetricsWrapsManagementApi(): void
    {
        $amqp = m::mock(Amqp::class)->makePartial();
        $amqp->shouldReceive('getQueueStatistics')
            ->once()
            ->with('jobs', '/', [])
            ->andReturn([
                'name' => 'jobs',
                'vhost' => '/',
                'messages' => 10,
                'messages_ready' => 8,
                'messages_unacknowledged' => 2,
                'consumers' => 1,
            ]);

        $metrics = $amqp->queueMetrics('jobs', '/');
        $this->assertInstanceOf(QueueMetrics::class, $metrics);
        $this->assertSame(10, $metrics->messageCount());
    }

    public function testConsumeInteropPassesInteropMessage(): void
    {
        $factory = m::mock(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class);
        $consumerFactory = m::mock(\Bschmitt\Amqp\Contracts\ConsumerFactoryInterface::class);
        $messageFactory = m::mock(\Bschmitt\Amqp\Factories\MessageFactory::class);
        $batch = m::mock(\Bschmitt\Amqp\Contracts\BatchManagerInterface::class);

        $consumer = m::mock(\Bschmitt\Amqp\Contracts\ConsumerInterface::class);
        $consumer->shouldReceive('consume')->once()->andReturnUsing(function ($queue, $callback) {
            $message = new \PhpAmqpLib\Message\AMQPMessage('{}', [
                'type' => 'ping',
                'content_type' => 'application/json',
            ]);
            $callback($message, null);

            return true;
        });
        $consumerFactory->shouldReceive('create')->once()->andReturn($consumer);

        $received = null;
        $amqp = new Amqp($factory, $consumerFactory, $messageFactory, $batch);
        $amqp->consumeInterop('rpc', function ($interop) use (&$received) {
            $received = $interop;
        });

        $this->assertInstanceOf(InteropMessage::class, $received);
    }

    public function testMetricsReturnsCollector(): void
    {
        $amqp = new Amqp(
            m::mock(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class),
            m::mock(\Bschmitt\Amqp\Contracts\ConsumerFactoryInterface::class),
            m::mock(\Bschmitt\Amqp\Factories\MessageFactory::class),
            m::mock(\Bschmitt\Amqp\Contracts\BatchManagerInterface::class)
        );
        $this->assertInstanceOf(MetricsCollector::class, $amqp->metrics());
    }
}
