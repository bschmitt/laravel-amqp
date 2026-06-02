<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\ConsumeMiddlewareInterface;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Compose a list of middlewares around a consume handler.
 *
 * Middlewares may be a {@see ConsumeMiddlewareInterface} instance or any
 * `callable(AMQPMessage $message, callable $next)`. The compiled pipeline
 * keeps the original 2-arg consume handler signature `(message, resolver)`.
 */
class ConsumePipeline
{
    /** @var array<int, callable|ConsumeMiddlewareInterface> */
    protected $middlewares = [];

    /**
     * @param callable|ConsumeMiddlewareInterface $middleware
     * @return $this
     */
    public function push($middleware): self
    {
        if (!is_callable($middleware) && !($middleware instanceof ConsumeMiddlewareInterface)) {
            throw new \InvalidArgumentException('Middleware must be callable or implement ConsumeMiddlewareInterface');
        }
        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * @param array<int, callable|ConsumeMiddlewareInterface> $middlewares
     * @return $this
     */
    public function pushMany(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->push($middleware);
        }

        return $this;
    }

    /**
     * Wrap the given handler with the registered middlewares.
     *
     * @param callable $handler function (AMQPMessage $message, $resolver): mixed
     * @return callable function (AMQPMessage $message, $resolver): mixed
     */
    public function wrap(callable $handler): callable
    {
        $middlewares = array_reverse($this->middlewares);

        return function (AMQPMessage $message, $resolver) use ($handler, $middlewares) {
            $pipeline = function (AMQPMessage $msg) use ($handler, $resolver) {
                return $handler($msg, $resolver);
            };

            foreach ($middlewares as $middleware) {
                $next = $pipeline;
                if ($middleware instanceof ConsumeMiddlewareInterface) {
                    $pipeline = function (AMQPMessage $msg) use ($middleware, $next) {
                        return $middleware->handle($msg, $next);
                    };
                } else {
                    $pipeline = function (AMQPMessage $msg) use ($middleware, $next) {
                        return call_user_func($middleware, $msg, $next);
                    };
                }
            }

            return $pipeline($message);
        };
    }
}
