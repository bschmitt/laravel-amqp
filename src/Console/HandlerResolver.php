<?php

namespace Bschmitt\Amqp\Console;

use Bschmitt\Amqp\Contracts\MessageHandlerInterface;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Closure;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * Resolves a user-supplied handler reference (a class name, callable, or
 * already-instantiated object) into a callable of the shape
 * `function (AMQPMessage $message, ConsumerInterface $resolver): void`.
 *
 * Accepted handler shapes:
 *  - A class implementing {@see MessageHandlerInterface}.
 *  - A class with an `__invoke(AMQPMessage, ConsumerInterface)` method.
 *  - An already-instantiated object of either of the above.
 *  - A plain `Closure` / callable with the same signature.
 *
 * Resolution goes through the Laravel container when one is supplied so
 * constructor dependencies of the handler are auto-wired (typical use case).
 */
class HandlerResolver
{
    /** @var \Illuminate\Contracts\Container\Container|null */
    protected $container;

    /**
     * @param \Illuminate\Contracts\Container\Container|null $container
     */
    public function __construct($container = null)
    {
        $this->container = $container;
    }

    /**
     * Resolve a handler reference into a callable.
     *
     * @param mixed $handler
     * @return Closure
     */
    public function resolve($handler): Closure
    {
        if ($handler instanceof Closure) {
            return $handler;
        }

        if (is_string($handler)) {
            if (!class_exists($handler)) {
                throw new RuntimeException(sprintf(
                    'Handler class [%s] does not exist.',
                    $handler
                ));
            }
            $handler = $this->make($handler);
        }

        if ($handler instanceof MessageHandlerInterface) {
            return function (AMQPMessage $message, ConsumerInterface $resolver) use ($handler) {
                $handler->handle($message, $resolver);
            };
        }

        if (is_object($handler) && method_exists($handler, '__invoke')) {
            return function (AMQPMessage $message, ConsumerInterface $resolver) use ($handler) {
                $handler($message, $resolver);
            };
        }

        if (is_callable($handler)) {
            return function (AMQPMessage $message, ConsumerInterface $resolver) use ($handler) {
                $handler($message, $resolver);
            };
        }

        throw new RuntimeException(sprintf(
            'Handler must implement %s, be invokable, or be a callable. Got [%s].',
            MessageHandlerInterface::class,
            is_object($handler) ? get_class($handler) : gettype($handler)
        ));
    }

    /**
     * Instantiate a handler class through the container (when available) or
     * fall back to `new $class`.
     *
     * @param string $class
     * @return object
     */
    protected function make(string $class)
    {
        if ($this->container !== null && method_exists($this->container, 'make')) {
            return $this->container->make($class);
        }

        return new $class();
    }
}
