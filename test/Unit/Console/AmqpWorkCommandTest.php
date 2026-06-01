<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpWorkCommand;
use Bschmitt\Amqp\Console\HandlerResolver;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Bschmitt\Amqp\Test\Support\Fixtures\InvokableHandler;
use Bschmitt\Amqp\Test\Support\Fixtures\RecordingHandler;
use Bschmitt\Amqp\Test\Support\Fixtures\ThrowingHandler;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpWorkCommandTest extends CommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingHandler::reset();
        InvokableHandler::reset();
        ThrowingHandler::reset();
    }

    public function testMissingHandlerOptionReturnsInvalid(): void
    {
        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
        ]);

        $this->assertSame(SymfonyCommand::INVALID, $result['status']);
        $this->assertStringContainsString('--handler option is required', $result['output']);
    }

    public function testUnknownHandlerClassReturnsFailure(): void
    {
        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => 'App\\Nope\\DoesNotExist',
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('does not exist', $result['output']);
    }

    public function testWorkerInvokesHandlerForEachDeliveredMessage(): void
    {
        $messages = [
            $this->fakeMessage('first body', 1),
            $this->fakeMessage('second body', 2),
        ];
        $consumer = $this->fakeConsumer();

        $this->amqp->shouldReceive('consume')
            ->once()
            ->with(
                'test-queue',
                Mockery::on(function ($callback) use ($messages, $consumer) {
                    $this->assertIsCallable($callback);
                    foreach ($messages as $msg) {
                        $callback($msg, $consumer);
                    }
                    return true;
                }),
                Mockery::on(function ($props) {
                    $this->assertTrue($props['persistent']);
                    return true;
                })
            )
            ->andReturn(true);

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => RecordingHandler::class,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertCount(2, RecordingHandler::$calls);
        $this->assertSame('first body', RecordingHandler::$calls[0]['message']->body);
        $this->assertSame('second body', RecordingHandler::$calls[1]['message']->body);
        $this->assertStringContainsString('Processed: 2, failed: 0', $result['output']);
    }

    public function testWorkerAcceptsInvokableHandler(): void
    {
        $message = $this->fakeMessage('hi', 1);
        $consumer = $this->fakeConsumer();

        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback) use ($message, $consumer) {
                $callback($message, $consumer);
                return true;
            });

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => InvokableHandler::class,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertCount(1, InvokableHandler::$calls);
    }

    public function testWorkerRejectsAndCountsFailuresWhenHandlerThrows(): void
    {
        $message = $this->fakeMessage('boom', 7);
        $consumer = $this->fakeConsumer();
        $consumer->shouldReceive('reject')
            ->once()
            ->with(Mockery::on(function ($m) use ($message) {
                return $m === $message;
            }), false);

        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback) use ($message, $consumer) {
                $callback($message, $consumer);
                return true;
            });

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => ThrowingHandler::class,
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertSame(1, ThrowingHandler::$callCount);
        $this->assertStringContainsString('handler exploded on purpose', $result['output']);
        $this->assertStringContainsString('Processed: 0, failed: 1', $result['output']);
    }

    public function testWorkerRequeuesWhenRequeueOnErrorIsSet(): void
    {
        $message = $this->fakeMessage('boom', 9);
        $consumer = $this->fakeConsumer();
        $consumer->shouldReceive('reject')->once()->with(Mockery::any(), true);

        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback) use ($message, $consumer) {
                $callback($message, $consumer);
                return true;
            });

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => ThrowingHandler::class,
            '--requeue-on-error' => true,
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('failed: 1', $result['output']);
    }

    public function testMaxMessagesStopsTheConsumer(): void
    {
        $messages = [
            $this->fakeMessage('a', 1),
            $this->fakeMessage('b', 2),
            $this->fakeMessage('c', 3),
        ];
        $consumer = $this->fakeConsumer();
        $consumer->shouldReceive('stopWhenProcessed')->once();

        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback) use ($messages, $consumer) {
                foreach ($messages as $msg) {
                    $callback($msg, $consumer);
                }
                return true;
            });

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => RecordingHandler::class,
            '--max-messages' => 2,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('Reached max-messages [2]', $result['output']);
    }

    public function testStopWhenEmptyDisablesPersistentMode(): void
    {
        $captured = null;
        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback, $props) use (&$captured) {
                $captured = $props;
                return true;
            });

        $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => RecordingHandler::class,
            '--stop-when-empty' => true,
        ]);

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('persistent', $captured);
        $this->assertFalse($captured['persistent']);
    }

    public function testOptionsFlowThroughIntoConsumeProperties(): void
    {
        $captured = null;
        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback, $props) use (&$captured) {
                $captured = $props;
                return true;
            });

        $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => RecordingHandler::class,
            '--connection' => 'production',
            '--exchange' => 'orders',
            '--exchange-type' => 'topic',
            '--routing-key' => ['order.created', 'order.updated'],
            '--prefetch-count' => 10,
            '--timeout' => 5,
        ]);

        $this->assertSame('production', $captured['use']);
        $this->assertSame('orders', $captured['exchange']);
        $this->assertSame('topic', $captured['exchange_type']);
        $this->assertSame(['order.created', 'order.updated'], $captured['routing']);
        $this->assertTrue($captured['qos']);
        $this->assertSame(10, $captured['qos_prefetch_count']);
        $this->assertSame(5, $captured['timeout']);
    }

    public function testSingleRoutingKeyIsPassedAsString(): void
    {
        $captured = null;
        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback, $props) use (&$captured) {
                $captured = $props;
                return true;
            });

        $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => RecordingHandler::class,
            '--routing-key' => ['order.created'],
        ]);

        $this->assertSame('order.created', $captured['routing']);
    }

    public function testConsumerLevelExceptionIsReportedAsFailure(): void
    {
        $this->amqp->shouldReceive('consume')
            ->once()
            ->andThrow(new \RuntimeException('broker unreachable'));

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => RecordingHandler::class,
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('Worker terminated with error', $result['output']);
        $this->assertStringContainsString('broker unreachable', $result['output']);
    }

    /* ------------------------------------------------------------------ */

    private function makeCommand(): AmqpWorkCommand
    {
        return new AmqpWorkCommand($this->amqp, new HandlerResolver($this->container));
    }

    /**
     * Build a fake AMQPMessage tied to a stub channel so `getDeliveryTag()`
     * and `body` are usable inside the worker's callback.
     */
    private function fakeMessage(string $body, int $deliveryTag): AMQPMessage
    {
        $message = new AMQPMessage($body);
        $channel = Mockery::mock(AMQPChannel::class);
        $message->setChannel($channel);
        $message->setDeliveryInfo($deliveryTag, false, '', '');

        return $message;
    }

    /**
     * Build a Mockery-backed ConsumerInterface that satisfies the methods
     * the worker callback may call.
     */
    private function fakeConsumer()
    {
        $consumer = Mockery::mock(ConsumerInterface::class);
        // Default no-op expectations so tests not interested in these don't fail.
        $consumer->shouldReceive('acknowledge')->byDefault();
        $consumer->shouldReceive('reject')->byDefault();
        $consumer->shouldReceive('stopWhenProcessed')->byDefault();

        return $consumer;
    }
}
