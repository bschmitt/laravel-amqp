<?php

namespace Bschmitt\Amqp\Test\Support\Fixtures;

use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\MessageHandlerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * Test fixture: a handler that always throws — used to assert the worker's
 * error path (reject, optional requeue, failure counters).
 */
class ThrowingHandler implements MessageHandlerInterface
{
    /** @var int */
    public static $callCount = 0;

    public function handle(AMQPMessage $message, ConsumerInterface $resolver, $typed = null): void
    {
        self::$callCount++;
        throw new RuntimeException('handler exploded on purpose');
    }

    public static function reset(): void
    {
        self::$callCount = 0;
    }
}
