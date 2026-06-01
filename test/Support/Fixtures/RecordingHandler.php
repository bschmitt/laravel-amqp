<?php

namespace Bschmitt\Amqp\Test\Support\Fixtures;

use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\MessageHandlerInterface;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Test fixture: a {@see MessageHandlerInterface} that records every call.
 *
 * Stored statically so tests using {@see CommandTester} can still inspect
 * invocations after the handler is resolved fresh inside the command.
 */
class RecordingHandler implements MessageHandlerInterface
{
    /** @var array<int, array{message: AMQPMessage, resolver: ConsumerInterface}> */
    public static $calls = [];

    public function handle(AMQPMessage $message, ConsumerInterface $resolver): void
    {
        self::$calls[] = ['message' => $message, 'resolver' => $resolver];
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
