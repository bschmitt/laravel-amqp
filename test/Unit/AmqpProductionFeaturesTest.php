<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\CorrelationContext;
use Bschmitt\Amqp\Support\ExchangeTopology;
use Bschmitt\Amqp\Support\TraceContext;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Mockery as m;

class AmqpProductionFeaturesTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        CorrelationContext::clear();
        parent::tearDown();
    }

    public function testDeclareExchangeTopologyCallsPublisherPerStep(): void
    {
        $factory = m::mock(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class);
        $consumerFactory = m::mock(\Bschmitt\Amqp\Contracts\ConsumerFactoryInterface::class);
        $messageFactory = m::mock(\Bschmitt\Amqp\Factories\MessageFactory::class);
        $batch = m::mock(\Bschmitt\Amqp\Contracts\BatchManagerInterface::class);

        $publisher = m::mock(\Bschmitt\Amqp\Contracts\PublisherInterface::class);

        $factory->shouldReceive('create')->twice()->andReturn($publisher);

        $amqp = new Amqp($factory, $consumerFactory, $messageFactory, $batch);

        $topology = ExchangeTopology::exchange('events')->bindQueue('a')->bindQueue('b');
        $amqp->declareExchangeTopology($topology);

        $this->assertTrue(true);
    }

    public function testApplyContextPropagationViaPublish(): void
    {
        $factory = m::mock(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class);
        $consumerFactory = m::mock(\Bschmitt\Amqp\Contracts\ConsumerFactoryInterface::class);
        $messageFactory = m::mock(\Bschmitt\Amqp\Factories\MessageFactory::class);
        $batch = m::mock(\Bschmitt\Amqp\Contracts\BatchManagerInterface::class);

        $capturedProps = null;
        $publisher = m::mock(\Bschmitt\Amqp\Contracts\PublisherInterface::class);
        $publisher->shouldReceive('publish')->once()->andReturn(true);
        $publisher->shouldReceive('getConnectionManager')->andReturn(null);

        $factory->shouldReceive('create')
            ->once()
            ->with(m::on(function ($props) use (&$capturedProps) {
                $capturedProps = $props;
                return isset($props['correlation_id'])
                    && isset($props['application_headers'][TraceContext::TRACEPARENT_HEADER]);
            }))
            ->andReturn($publisher);

        $messageFactory->shouldReceive('create')->once()->andReturn(m::mock(\Bschmitt\Amqp\Models\Message::class));

        $amqp = new Amqp($factory, $consumerFactory, $messageFactory, $batch);
        CorrelationContext::set('corr-123');
        $amqp->publish('rk', 'body', [
            'propagate_correlation' => true,
            'propagate_trace' => true,
        ]);

        $this->assertSame('corr-123', $capturedProps['correlation_id']);
    }
}
