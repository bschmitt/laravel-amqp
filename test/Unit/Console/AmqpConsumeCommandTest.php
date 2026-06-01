<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpConsumeCommand;
use Bschmitt\Amqp\Console\HandlerResolver;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Bschmitt\Amqp\Test\Support\Fixtures\RecordingHandler;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpConsumeCommandTest extends CommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingHandler::reset();
    }

    public function testMissingHandlerReturnsInvalid(): void
    {
        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'q',
        ]);

        $this->assertSame(SymfonyCommand::INVALID, $result['status']);
    }

    public function testDefaultMaxMessagesIsOneAndPersistentIsFalse(): void
    {
        $message = $this->fakeMessage('only-one', 1);
        $consumer = $this->fakeConsumer();
        $consumer->shouldReceive('stopWhenProcessed')->once();

        $capturedProps = null;
        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($q, $callback, $props) use ($message, $consumer, &$capturedProps) {
                $capturedProps = $props;
                $callback($message, $consumer);
                return true;
            });

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'q',
            '--handler' => RecordingHandler::class,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertCount(1, RecordingHandler::$calls);
        $this->assertFalse($capturedProps['persistent']);
    }

    public function testStopsAtSuppliedMaxMessages(): void
    {
        $messages = [
            $this->fakeMessage('m1', 1),
            $this->fakeMessage('m2', 2),
            $this->fakeMessage('m3', 3),
        ];
        $consumer = $this->fakeConsumer();
        $consumer->shouldReceive('stopWhenProcessed')->once();

        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($q, $callback) use ($messages, $consumer) {
                foreach ($messages as $m) {
                    $callback($m, $consumer);
                }
                return true;
            });

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'q',
            '--handler' => RecordingHandler::class,
            '--max-messages' => 2,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertCount(2, RecordingHandler::$calls, 'should stop after 2 messages');
    }

    public function testAllFlagOverridesMaxMessages(): void
    {
        $consumer = $this->fakeConsumer();

        $this->amqp->shouldReceive('consume')
            ->once()
            ->andReturnUsing(function ($q, $callback) {
                // Simulate broker draining naturally (no calls).
                return true;
            });

        $result = $this->runCommand($this->makeCommand(), [
            'queue' => 'q',
            '--handler' => RecordingHandler::class,
            '--all' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('Consuming up to all message', $result['output']);
    }

    /* ------------------------------------------------------------------ */

    private function makeCommand(): AmqpConsumeCommand
    {
        return new AmqpConsumeCommand($this->amqp, new HandlerResolver($this->container));
    }

    private function fakeMessage(string $body, int $deliveryTag): AMQPMessage
    {
        $message = new AMQPMessage($body);
        $message->setChannel(Mockery::mock(AMQPChannel::class));
        $message->setDeliveryInfo($deliveryTag, false, '', '');

        return $message;
    }

    private function fakeConsumer()
    {
        $consumer = Mockery::mock(ConsumerInterface::class);
        $consumer->shouldReceive('acknowledge')->byDefault();
        $consumer->shouldReceive('reject')->byDefault();
        $consumer->shouldReceive('stopWhenProcessed')->byDefault();

        return $consumer;
    }
}
