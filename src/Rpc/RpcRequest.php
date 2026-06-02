<?php

namespace Bschmitt\Amqp\Rpc;

/**
 * Base class for gRPC-lite request DTOs.
 *
 * Optionally override {@see responseClass()} so {@see RpcDispatcher::call()}
 * can hydrate the broker reply into the right response DTO.
 */
abstract class RpcRequest extends RpcMessage
{
    /**
     * FQCN of the expected response DTO, or null to return a raw decoded array.
     *
     * @return class-string<RpcResponse>|null
     */
    public static function responseClass()
    {
        return null;
    }
}
