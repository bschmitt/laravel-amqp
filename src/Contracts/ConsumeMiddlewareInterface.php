<?php

namespace Bschmitt\Amqp\Contracts;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Middleware for the consume pipeline.
 *
 * Each middleware receives the incoming AMQP message and a `$next` closure
 * that invokes the rest of the pipeline (and ultimately the user handler).
 * Middlewares may short-circuit by not calling `$next`.
 */
interface ConsumeMiddlewareInterface
{
    /**
     * @param AMQPMessage $message
     * @param callable    $next function (AMQPMessage $message): mixed
     * @return mixed
     */
    public function handle(AMQPMessage $message, callable $next);
}
