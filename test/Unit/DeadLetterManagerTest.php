<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Events\DeadLetterDetected;
use Bschmitt\Amqp\Events\DeadLetterPurged;
use Bschmitt\Amqp\Events\DeadLetterReplayed;
use Bschmitt\Amqp\Support\DeadLetterManager;
use Bschmitt\Amqp\Support\EventDispatcher;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use LogicException;
use Mockery as m;
use PhpAmqpLib\Message\AMQPMessage;

class DeadLetterManagerTest extends BaseTestCase
{
    /** @var array<int, object> */
    protected $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        EventDispatcher::setInstance(new EventDispatcher());
        $this->captured = [];

        $bus = EventDispatcher::instance();
        $bus->listen(DeadLetterDetected::class, function ($e) {
            $this->captured[] = $e;
        });
        $bus->listen(DeadLetterReplayed::class, function ($e) {
            $this->captured[] = $e;
        });
        $bus->listen(DeadLetterPurged::class, function ($e) {
            $this->captured[] = $e;
        });
    }

    protected function tearDown(): void
    {
        EventDispatcher::setInstance(null);
        parent::tearDown();
    }

    public function testCountUsesManagementApiStats(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('getQueueStatistics')
            ->once()
            ->with('orders.dlq', null, m::any())
            ->andReturn(['messages' => 7]);

        $manager = new DeadLetterManager($amqp);
        $this->assertSame(7, $manager->for('orders.dlq')->count());

        $this->assertCount(1, $this->captured);
        $this->assertInstanceOf(DeadLetterDetected::class, $this->captured[0]);
        $this->assertSame(7, $this->captured[0]->messageCount);
    }

    public function testCountDoesNotFireEventWhenEmpty(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('getQueueStatistics')->andReturn(['messages' => 0]);

        (new DeadLetterManager($amqp))->for('orders.dlq')->count();

        $this->assertSame([], $this->captured);
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

    public function testPurgeForwardsToAmqpAndDispatchesEvent(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('queuePurge')
            ->once()
            ->with('orders.dlq', m::any())
            ->andReturn(3);

        $manager = new DeadLetterManager($amqp);
        $this->assertSame(3, $manager->for('orders.dlq')->purge());

        $this->assertCount(1, $this->captured);
        $this->assertInstanceOf(DeadLetterPurged::class, $this->captured[0]);
        $this->assertSame(3, $this->captured[0]->count);
    }

    public function testPeekDelegatesToAmqpPeekQueue(): void
    {
        $amqp = m::mock(Amqp::class);
        $sample = [
            ['body' => 'a', 'properties' => [], 'headers' => ['x-last-error' => 'boom']],
        ];
        $amqp->shouldReceive('peekQueue')
            ->once()
            ->with('orders.dlq', 5, m::any())
            ->andReturn($sample);

        $result = (new DeadLetterManager($amqp))->for('orders.dlq')->peek(5);
        $this->assertSame($sample, $result);
    }

    public function testSummarizeCategorizesByReasonAndError(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('peekQueue')->andReturn([
            ['body' => '', 'properties' => [], 'headers' => [
                'x-death' => [['queue' => 'orders', 'reason' => 'rejected', 'routing-keys' => ['orders.created']]],
                'x-last-error' => 'PaymentDeclined',
                'x-first-failed-at' => 1700000000,
                'x-retry-attempt' => 3,
            ]],
            ['body' => '', 'properties' => [], 'headers' => [
                'x-death' => [['queue' => 'orders', 'reason' => 'rejected']],
                'x-last-error' => 'PaymentDeclined',
                'x-first-failed-at' => 1700000050,
                'x-retry-attempt' => 5,
            ]],
            ['body' => '', 'properties' => [], 'headers' => [
                'x-death' => [['queue' => 'orders', 'reason' => 'expired']],
                'x-last-error' => 'StockOut',
            ]],
        ]);

        $summary = (new DeadLetterManager($amqp))->for('orders.dlq')->summarize(50);

        $this->assertSame(3, $summary['sampled']);
        $this->assertSame(2, $summary['by_reason']['rejected']);
        $this->assertSame(1, $summary['by_reason']['expired']);
        $this->assertSame(3, $summary['by_origin']['orders']);
        $this->assertSame(2, $summary['top_errors']['PaymentDeclined']);
        $this->assertSame(1700000000, $summary['oldest_failed_at']);
        $this->assertSame(5, $summary['max_retry_attempt']);

        $detected = array_filter($this->captured, function ($e) {
            return $e instanceof DeadLetterDetected;
        });
        $this->assertCount(1, $detected);
    }

    public function testSummarizeWrapsErrors(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('peekQueue')->andThrow(new \RuntimeException('broker offline'));

        $summary = (new DeadLetterManager($amqp))->for('orders.dlq')->summarize();
        $this->assertSame('broker offline', $summary['error']);
    }

    public function testReplayConsumesDlqAndPublishesToTarget(): void
    {
        $amqp = m::mock(Amqp::class);
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

        $amqp->shouldReceive('publish')->twice();

        $manager = new DeadLetterManager($amqp);
        $count = $manager->for('orders.dlq')->replayTo('orders');

        $this->assertSame(2, $count);
        $replayed = array_filter($this->captured, function ($e) {
            return $e instanceof DeadLetterReplayed;
        });
        $this->assertCount(1, $replayed);
        $event = array_values($replayed)[0];
        $this->assertSame('orders', $event->targetQueue);
        $this->assertSame(2, $event->count);
    }
}
