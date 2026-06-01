<?php

namespace Bschmitt\Amqp\Test\Support\Fixtures;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Test fixture: a plain invokable handler (no interface). Used to verify
 * {@see Bschmitt\Amqp\Console\HandlerResolver} accepts `__invoke()` shapes.
 */
class InvokableHandler
{
    /** @var array<int, array{message: AMQPMessage, resolver: mixed}> */
    public static $calls = [];

    public function __invoke(AMQPMessage $message, $resolver): void
    {
        self::$calls[] = ['message' => $message, 'resolver' => $resolver];
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
