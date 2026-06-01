<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpWorkCommand;
use Bschmitt\Amqp\Console\HandlerResolver;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Support\DeadLetterTopology;
use Bschmitt\Amqp\Support\RetryHandler;
use Bschmitt\Amqp\Support\RetryPolicy;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Bschmitt\Amqp\Test\Support\Fixtures\InvokableHandler;
use Bschmitt\Amqp\Test\Support\Fixtures\OrderCreatedMessage;
use Bschmitt\Amqp\Test\Support\Fixtures\RecordingHandler;
use Bschmitt\Amqp\Test\Support\Fixtures\ThrowingHandler;
use Bschmitt\Amqp\Test\Support\Fixtures\TypedRecordingHandler;
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
        TypedRecordingHandler::reset();
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

    public function testRetryOptionWiresWorkPropertiesWithDeadLetterExchange(): void
    {
        $captured = null;
        $this->amqp->shouldReceive('retryHandler')
            ->once()
            ->andReturnUsing(function ($handler, DeadLetterTopology $topology) {
                $this->assertSame('test-queue', $topology->getQueue());
                $this->assertSame(3, $topology->getRetryPolicy()->maxAttempts());
                $this->assertSame(RetryPolicy::STRATEGY_FIXED, $topology->getRetryPolicy()->strategy());
                $this->assertSame(250, $topology->getRetryPolicy()->baseDelayMs());

                return new RetryHandler(
                    $handler,
                    $topology,
                    Mockery::mock(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class)
                );
            });

        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback, $props) use (&$captured) {
                $captured = $props;
                return true;
            });

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => RecordingHandler::class,
            '--retry' => 3,
            '--retry-delay' => 250,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertIsArray($captured);
        $this->assertSame('test-queue', $captured['queue']);
        $this->assertArrayHasKey('queue_properties', $captured);
        $this->assertSame('test-queue.dlq', $captured['queue_properties']['x-dead-letter-routing-key']);
    }

    public function testRetryOptionSupportsExponentialBackoff(): void
    {
        $this->amqp->shouldReceive('retryHandler')
            ->once()
            ->andReturnUsing(function ($handler, DeadLetterTopology $topology) {
                $policy = $topology->getRetryPolicy();
                $this->assertSame(RetryPolicy::STRATEGY_EXPONENTIAL, $policy->strategy());
                $this->assertSame(100, $policy->baseDelayMs());
                $this->assertEqualsWithDelta(2.5, $policy->multiplier(), 0.0001);
                $this->assertSame(5000, $policy->maxDelayMs());

                return new RetryHandler(
                    $handler,
                    $topology,
                    Mockery::mock(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class)
                );
            });
        $this->amqp->shouldReceive('consume')->once()->andReturn(true);

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => RecordingHandler::class,
            '--retry' => 4,
            '--retry-backoff' => 'exponential',
            '--retry-delay' => 100,
            '--retry-multiplier' => 2.5,
            '--retry-max-delay' => 5000,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
    }

    public function testDlqOptionOverridesDefaultDlqName(): void
    {
        $captured = null;
        $this->amqp->shouldReceive('retryHandler')
            ->once()
            ->andReturnUsing(function ($handler, DeadLetterTopology $topology) {
                return new RetryHandler(
                    $handler,
                    $topology,
                    Mockery::mock(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class)
                );
            });
        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback, $props) use (&$captured) {
                $captured = $props;
                return true;
            });

        $this->runCommand($this->makeCommand(), [
            'queue' => 'orders',
            '--handler' => RecordingHandler::class,
            '--retry' => 2,
            '--dlq' => 'orders.failed',
        ]);

        $this->assertSame('orders.failed', $captured['queue_properties']['x-dead-letter-routing-key']);
    }

    public function testDeclareTopologyFlagCallsAmqpDeclareRetryTopology(): void
    {
        $this->amqp->shouldReceive('declareRetryTopology')
            ->once()
            ->with(Mockery::on(function ($t) {
                return $t instanceof DeadLetterTopology && $t->getQueue() === 'orders';
            }));

        $this->amqp->shouldReceive('retryHandler')
            ->once()
            ->andReturnUsing(function ($handler, DeadLetterTopology $topology) {
                return new RetryHandler(
                    $handler,
                    $topology,
                    Mockery::mock(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class)
                );
            });
        $this->amqp->shouldReceive('consume')->once()->andReturn(true);

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'orders',
            '--handler' => RecordingHandler::class,
            '--retry' => 2,
            '--declare-topology' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('Declared retry topology', $result['output']);
    }

    public function testInvalidRetryBackoffOptionReturnsInvalid(): void
    {
        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'orders',
            '--handler' => RecordingHandler::class,
            '--retry' => 2,
            '--retry-backoff' => 'parabolic',
        ]);

        $this->assertSame(SymfonyCommand::INVALID, $result['status']);
        $this->assertStringContainsString('Invalid retry configuration', $result['output']);
    }

    public function testNegativeRetryOptionReturnsInvalid(): void
    {
        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'orders',
            '--handler' => RecordingHandler::class,
            '--retry' => -1,
        ]);

        $this->assertSame(SymfonyCommand::INVALID, $result['status']);
    }

    public function testNoRetryOptionLeavesHandlerUnwrapped(): void
    {
        $this->amqp->shouldNotReceive('retryHandler');
        $this->amqp->shouldNotReceive('declareRetryTopology');
        $this->amqp->shouldReceive('consume')->once()->andReturn(true);

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'test-queue',
            '--handler' => RecordingHandler::class,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
    }

    public function testInvalidContractClassReturnsInvalid(): void
    {
        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'orders',
            '--handler' => RecordingHandler::class,
            '--contract' => 'App\\Nope\\NotAContract',
        ]);

        $this->assertSame(SymfonyCommand::INVALID, $result['status']);
        $this->assertStringContainsString('does not implement', $result['output']);
    }

    public function testContractOptionDeserializesTypedMessageForHandler(): void
    {
        $body = json_encode([
            'orderId' => 'order-cli-1',
            'total' => 42.0,
            'currency' => 'USD',
        ]);
        $consumer = $this->fakeConsumer();

        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback) use ($body, $consumer) {
                $callback($this->fakeMessage($body, 1), $consumer);
                return true;
            });

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'orders',
            '--handler' => TypedRecordingHandler::class,
            '--contract' => OrderCreatedMessage::class,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertCount(1, TypedRecordingHandler::$calls);
        $typed = TypedRecordingHandler::$calls[0]['typed'];
        $this->assertInstanceOf(OrderCreatedMessage::class, $typed);
        $this->assertSame('order-cli-1', $typed->orderId);
        $this->assertEquals(42.0, $typed->total);
        $this->assertSame('USD', $typed->currency);
    }

    public function testWrapWithContractThrowsWhenValidateSchemaEnabledAndPayloadInvalid(): void
    {
        $command = $this->makeCommand();
        $method = new \ReflectionMethod($command, 'wrapWithContract');
        $method->setAccessible(true);

        $handlerInvoked = false;
        $inner = function () use (&$handlerInvoked) {
            $handlerInvoked = true;
        };

        $wrapped = $method->invoke($command, $inner, OrderCreatedMessage::class, true);

        try {
            $wrapped($this->fakeMessage('{"orderId":"x"}', 1), $this->fakeConsumer());
            $this->fail('Expected SchemaValidationException');
        } catch (\Bschmitt\Amqp\Exception\SchemaValidationException $e) {
            $this->assertFalse($handlerInvoked);
            $this->assertNotEmpty($e->errors());
        }
    }

    public function testValidateSchemaRejectsInvalidPayloadBeforeHandlerRuns(): void
    {
        $consumer = $this->fakeConsumer();

        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($queue, $callback) use ($consumer) {
                $callback($this->fakeMessage('{"orderId":"x"}', 1), $consumer);
                return true;
            });

        $command = $this->makeCommand();
        $result = $this->runCommand($command, [
            'queue' => 'orders',
            '--handler' => TypedRecordingHandler::class,
            '--contract' => OrderCreatedMessage::class,
            '--validate-schema' => true,
        ]);

        $this->assertTrue((bool) $command->option('validate-schema'));
        $this->assertSame(SymfonyCommand::FAILURE, $result['status'], 'all-failed workers exit non-zero');
        $this->assertCount(0, TypedRecordingHandler::$calls);
        $this->assertStringContainsString('failed: 1', $result['output']);
        $this->assertStringContainsString('Schema validation failed', $result['output']);
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
