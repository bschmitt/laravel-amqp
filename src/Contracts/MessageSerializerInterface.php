<?php

namespace Bschmitt\Amqp\Contracts;

/**
 * Strategy used by the typed-messaging helpers to convert payload arrays
 * to/from wire bodies.
 *
 * The default implementation ({@see \Bschmitt\Amqp\Support\JsonMessageSerializer})
 * uses JSON. Applications can supply any other format (MessagePack, Protobuf,
 * Avro, …) by binding their own implementation in the container.
 *
 * Implementations MUST be deterministic and side-effect-free; the helpers may
 * call them once per delivery and assume the same input produces the same
 * output.
 */
interface MessageSerializerInterface
{
    /**
     * Encode a payload array into a transport-ready string.
     *
     * Implementations SHOULD throw {@see \InvalidArgumentException} (or a
     * subclass) when the payload cannot be encoded.
     *
     * @param array<string, mixed> $payload
     * @return string
     */
    public function serialize(array $payload): string;

    /**
     * Decode a transport body back into a payload array.
     *
     * Implementations SHOULD throw {@see \InvalidArgumentException} (or a
     * subclass) when the body cannot be decoded into an array.
     *
     * @param string $body
     * @return array<string, mixed>
     */
    public function deserialize(string $body): array;

    /**
     * MIME content-type advertised on the AMQP message properties.
     *
     * @return string e.g. `application/json`
     */
    public function contentType(): string;
}
