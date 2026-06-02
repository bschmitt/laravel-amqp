<?php

namespace Bschmitt\Amqp\Rpc;

/**
 * Service contract shared between RPC clients and servers.
 *
 * A "service" is a logical grouping of remote methods routed through a
 * single AMQP queue. Subclasses declare:
 *
 *  - {@see queue()}   — the AMQP queue requests land on.
 *  - {@see methods()} — a `[RpcRequest::class => 'handlerMethod']` map.
 *
 * Server-side handler classes typically extend the same service class
 * (or implement matching method names) and are registered via
 * {@see RpcDispatcher::register()}.
 */
abstract class RpcService
{
    /**
     * AMQP queue this service listens on.
     *
     * @return string
     */
    abstract public static function queue(): string;

    /**
     * Logical service name (used in headers + tracing).
     *
     * Defaults to the short class name without the `Service` suffix.
     *
     * @return string
     */
    public static function name(): string
    {
        $parts = explode('\\', static::class);
        $short = (string) end($parts);
        if (substr($short, -7) === 'Service') {
            $short = substr($short, 0, -7);
        }
        return $short !== '' ? $short : static::class;
    }

    /**
     * Map of request DTO class → handler method name on the service instance.
     *
     * Example:
     * ```
     * return [
     *     GetUserRequest::class    => 'getUser',
     *     CreateUserRequest::class => 'createUser',
     * ];
     * ```
     *
     * @return array<class-string<RpcRequest>, string>
     */
    abstract public static function methods(): array;

    /**
     * Routing key used to publish the request. Defaults to {@see queue()}.
     *
     * @return string
     */
    public static function routingKey(): string
    {
        return static::queue();
    }

    /**
     * Exchange used to publish the request. Empty string (default exchange)
     * routes by queue name and works out of the box.
     *
     * @return string
     */
    public static function exchange(): string
    {
        return '';
    }
}
