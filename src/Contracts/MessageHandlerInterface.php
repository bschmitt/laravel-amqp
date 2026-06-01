<?php

namespace Bschmitt\Amqp\Contracts;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Contract for message handlers used by the AMQP artisan commands
 * (`amqp:work`, `amqp:consume`, `amqp:listen`).
 *
 * Handlers receive the raw {@see AMQPMessage} and the active {@see ConsumerInterface}
 * (referred to as the "resolver") which exposes acknowledge/reject/reply helpers.
 *
 * Implementations are responsible for acknowledging or rejecting the message; if a
 * handler returns without calling either, the worker will reject the message
 * (with or without requeue, depending on the command flags).
 *
 * For ad-hoc handlers, any class with an `__invoke($message, $resolver)` method
 * is also accepted by the commands — implementing this interface is optional
 * but recommended for clarity.
 */
interface MessageHandlerInterface
{
    /**
     * Handle a single delivered message.
     *
     * When `amqp:work --contract=...` is used, a third argument is passed:
     * the deserialised {@see MessageContractInterface} instance. Handlers that
     * do not need typed messages may omit this parameter (default `null`).
     *
     * @param AMQPMessage       $message  Delivered AMQP message.
     * @param ConsumerInterface $resolver Consumer used to ack/reject/reply.
     * @param mixed             $typed    Deserialised contract DTO, or null.
     * @return void
     */
    public function handle(AMQPMessage $message, ConsumerInterface $resolver, $typed = null): void;
}
