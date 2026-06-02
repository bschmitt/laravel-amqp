<?php

namespace Bschmitt\Amqp\Rpc;

use Bschmitt\Amqp\Support\TypedMessage;

/**
 * Base DTO for {@see RpcRequest} and {@see RpcResponse}.
 *
 * Adds the gRPC-lite ergonomic `make()` factory on top of the
 * reflection-based `TypedMessage`. Keeps PHP 7.3 compatible: public
 * properties (no typed promotion) and a parameterless constructor so
 * `make()` can hydrate via reflection.
 */
abstract class RpcMessage extends TypedMessage
{
    /**
     * Create a populated instance from an associative array.
     *
     * ```
     * GetUserRequest::make(['id' => 5])
     * ```
     *
     * @param array<string, mixed> $payload
     * @return static
     */
    public static function make(array $payload = [])
    {
        return static::fromPayload($payload);
    }
}
