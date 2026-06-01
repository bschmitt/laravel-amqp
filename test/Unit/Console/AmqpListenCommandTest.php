<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpListenCommand;
use Bschmitt\Amqp\Console\HandlerResolver;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Bschmitt\Amqp\Test\Support\Fixtures\RecordingHandler;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpListenCommandTest extends CommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingHandler::reset();
    }

    public function testRoutingKeysAndHandlerArePassedToListen(): void
    {
        $message = $this->fakeMessage('payload', 1);
        $consumer = $this->fakeConsumer();

        $capturedKeys = null;
        $capturedProps = null;
        $this->amqp->shouldReceive('listen')
            ->once()
            ->andReturnUsing(function ($keys, $callback, $props) use ($message, $consumer, &$capturedKeys, &$capturedProps) {
                $capturedKeys = $keys;
                $capturedProps = $props;
                $callback($message, $consumer);
                return true;
            });

        $result = $this->runCommand($this->makeCommand(), [
            'routing-keys' => ['order.created', 'order.updated'],
            '--handler' => RecordingHandler::class,
            '--queue' => 'my-listener',
            '--exchange' => 'orders',
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertSame(['order.created', 'order.updated'], $capturedKeys);
        $this->assertSame('my-listener', $capturedProps['queue']);
        $this->assertSame('orders', $capturedProps['exchange']);
        $this->assertSame('topic', $capturedProps['exchange_type']);
        $this->assertCount(1, RecordingHandler::$calls);
    }

    public function testNoRoutingKeysReturnsInvalid(): void
    {
        $result = $this->runCommand($this->makeCommand(), [
            'routing-keys' => [],
            '--handler' => RecordingHandler::class,
        ]);

        $this->assertSame(SymfonyCommand::INVALID, $result['status']);
        $this->assertStringContainsString('At least one routing key', $result['output']);
    }

    public function testNoAutoDeleteSetsQueuePropertyFalse(): void
    {
        $capturedProps = null;
        $this->amqp->shouldReceive('listen')
            ->once()
            ->andReturnUsing(function ($keys, $callback, $props) use (&$capturedProps) {
                $capturedProps = $props;
                return true;
            });

        $this->runCommand($this->makeCommand(), [
            'routing-keys' => ['evt.*'],
            '--handler' => RecordingHandler::class,
            '--no-auto-delete' => true,
        ]);

        $this->assertFalse($capturedProps['queue_auto_delete']);
    }

    /* ------------------------------------------------------------------ */

    private function makeCommand(): AmqpListenCommand
    {
        return new AmqpListenCommand($this->amqp, new HandlerResolver($this->container));
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
