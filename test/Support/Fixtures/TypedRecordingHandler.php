<?php

namespace Bschmitt\Amqp\Test\Support\Fixtures;

use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\MessageHandlerInterface;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Like {@see RecordingHandler} but also captures the optional third
 * `$typed` argument injected by `amqp:work --contract`.
 */
class TypedRecordingHandler implements MessageHandlerInterface
{
    /** @var array<int, array{message: AMQPMessage, resolver: ConsumerInterface, typed: mixed}> */
    public static $calls = [];

    public function handle(AMQPMessage $message, ConsumerInterface $resolver, $typed = null): void
    {
        self::$calls[] = [
            'message' => $message,
            'resolver' => $resolver,
            'typed' => $typed,
        ];
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
