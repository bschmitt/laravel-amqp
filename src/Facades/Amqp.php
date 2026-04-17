<?php

namespace Bschmitt\Amqp\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void publish(string $routingKey, mixed $message, array $properties = [])
 * @method static void consume(string $queue, callable $callback, array $properties= [])
 * @method static \Bschmitt\Amqp\Message message(string $body, array $properties = [])
 * @method static void batchPublish(array $properties = [])
 * @method static void batchBasicPublish(string $routing, mixed $message)
 *
 * @see Bschmitt\Amqp\Core\Amqp
 */
class Amqp extends Facade
{

    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'Amqp';
    }
}
