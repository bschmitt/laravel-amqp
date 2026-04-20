<?php

namespace Bschmitt\Amqp\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void publish(string $routingKey, mixed $message, array $properties = [])
 * @method static void consume(string $queue, callable $callback, array $properties= [])
 * @method static \Bschmitt\Amqp\Models\Message message(string $body, array $properties = [])
 * @method static void batchPublish(array $properties = [])
 * @method static void batchBasicPublish(string $routing, mixed $message)
 * @method static mixed|null rpc(string $routingKey, $request, array $properties = [], int $timeout = 30)
 * @method static bool listen(array $routingKeys, Closure $callback, array $properties = [])
 *
 * @see \Bschmitt\Amqp\Core\Amqp
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
