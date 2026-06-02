<?php

namespace Bschmitt\Amqp\Contracts;

/**
 * Marker interface for Laravel events that should auto-publish to RabbitMQ.
 *
 * Pair with {@see \Bschmitt\Amqp\Events\AmqpEventListener}. Implementing
 * events MAY also expose the following static or instance helpers; defaults
 * are derived from the event class name when absent.
 *
 *  - `amqpRouting(): string`   — routing key (default: snake-case class name)
 *  - `amqpExchange(): string`  — exchange  (default: package default)
 *  - `amqpPayload(): array`    — serialised payload (default: public properties)
 */
interface ShouldPublishToAmqpInterface
{
}
