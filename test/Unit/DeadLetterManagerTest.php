<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\DeadLetterManager;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use LogicException;
use Mockery as m;
use PhpAmqpLib\Message\AMQPMessage;

class DeadLetterManagerTest extends BaseTestCase
{
    public function testCountUsesManagementApiStats(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('getQueueStatistics')
            ->once()
            ->with('orders.dlq', null, m::any())
            ->andReturn(['messages' => 7]);

        $manager = new DeadLetterManager($amqp);
        $this->assertSame(7, $manager->for('orders.dlq')->count());
    }

    public function testForRejectsEmptyName(): void
    {
        $manager = new DeadLetterManager(m::mock(Amqp::class));
        $this->expectException(\InvalidArgumentException::class);
        $manager->for('');
    }

    public function testMissingForThrows(): void
    {
        $manager = new DeadLetterManager(m::mock(Amqp::class));
        $this->expectException(LogicException::class);
        $manager->count();
    }

    public function testPurgeForwardsToAmqp(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('queuePurge')
            ->once()
            ->with('orders.dlq', m::any())
            ->andReturn(3);

        $manager = new DeadLetterManager($amqp);
        $this->assertSame(3, $manager->for('orders.dlq')->purge());
    }

    public function testReplayConsumesDlqAndPublishesToTarget(): void
    {
        $amqp = m::mock(Amqp::class);

        // First call: consume() — drain two messages and stop.
        $amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback, $properties) {
                $consumer = new class {
                    public function acknowledge($m) {}
                    public function reject($m, $r = false) {}
                    public function stopWhenProcessed() {}
                };
                $callback(new AMQPMessage('body-1', []), $consumer);
                $callback(new AMQPMessage('body-2', []), $consumer);

                return true;
            });

        // Each replayed message must be published to the target queue.
        $amqp->shouldReceive('publish')->twice();

        $manager = new DeadLetterManager($amqp);
        $count = $manager->for('orders.dlq')->replayTo('orders');

        $this->assertSame(2, $count);
    }
}
