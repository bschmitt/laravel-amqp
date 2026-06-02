<?php

namespace Bschmitt\Amqp\Facades;

use Bschmitt\Amqp\Rpc\RpcDispatcher;
use Illuminate\Support\Facades\Facade;

/**
 * gRPC-lite facade.
 *
 * @method static \Bschmitt\Amqp\Rpc\RpcDispatcher defaultTimeout(int $seconds)
 * @method static \Bschmitt\Amqp\Rpc\RpcDispatcher register(string $service, $handler)
 * @method static mixed call(string $service, \Bschmitt\Amqp\Rpc\RpcRequest $request, ?int $timeoutSeconds = null, array $properties = [])
 * @method static bool serve(string $service, $handler = null, array $properties = [])
 * @method static array registered()
 *
 * @see \Bschmitt\Amqp\Rpc\RpcDispatcher
 */
class Rpc extends Facade
{
    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return RpcDispatcher::class;
    }
}
