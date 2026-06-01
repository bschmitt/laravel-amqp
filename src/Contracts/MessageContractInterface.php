<?php

namespace Bschmitt\Amqp\Contracts;

/**
 * Contract for typed message DTOs.
 *
 * A "message contract" is a plain PHP class that knows how to:
 *  - represent itself as a payload array (`toPayload()`); and
 *  - rebuild itself from that array on the consumer side (`fromPayload()`).
 *
 * The package's typed-messaging helpers ({@see \Bschmitt\Amqp\Core\Amqp::publishTyped()},
 * {@see \Bschmitt\Amqp\Core\Amqp::consumeTyped()}) hand the payload off to the
 * configured {@see MessageSerializerInterface} (JSON by default) for transport.
 *
 * Implementations MAY also expose a static `schema()` method returning a JSON
 * Schema-style array (see {@see \Bschmitt\Amqp\Support\SchemaValidator}); when
 * present the typed helpers validate inbound and outbound payloads against it
 * and throw {@see \Bschmitt\Amqp\Exception\SchemaValidationException} on
 * mismatch.
 *
 * The return type of {@see fromPayload()} is intentionally omitted from the
 * interface signature so subclasses can return their concrete type without
 * relying on PHP 8.0+ `static` return types — this keeps the package
 * compatible all the way down to PHP 7.3.
 */
interface MessageContractInterface
{
    /**
     * Convert the message into a plain array suitable for serialisation.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array;

    /**
     * Reconstruct a contract instance from a previously-serialised payload.
     *
     * Implementations should return an instance of `static` (or `self`). The
     * return type is left off so subclasses can narrow it freely while still
     * being compatible with PHP 7.3.
     *
     * @param array<string, mixed> $payload
     * @return self
     */
    public static function fromPayload(array $payload);
}
