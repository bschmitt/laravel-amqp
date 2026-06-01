<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\JsonMessageSerializer;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use InvalidArgumentException;

/**
 * Unit coverage for {@see JsonMessageSerializer}.
 *
 * Focuses on three axes:
 *  - Round-trip correctness for typical payload shapes.
 *  - Default flag behaviour (UTF-8 + slash preservation).
 *  - That malformed input surfaces as `InvalidArgumentException` (which the
 *    typed-messaging helpers translate into either a retry or a DLQ
 *    routing decision via the rest of the package).
 */
class JsonMessageSerializerTest extends BaseTestCase
{
    public function testContentTypeIsApplicationJson(): void
    {
        $this->assertSame('application/json', (new JsonMessageSerializer())->contentType());
    }

    public function testRoundTripPreservesScalarsAndNestedStructures(): void
    {
        $serializer = new JsonMessageSerializer();
        $payload = [
            'id' => 'order-1',
            'total' => 99.5,
            'tags' => ['priority', 'gift-wrapped'],
            'meta' => ['source' => 'web', 'utf' => 'café'],
            'nullable' => null,
            'bool' => true,
        ];

        $body = $serializer->serialize($payload);
        $this->assertSame($payload, $serializer->deserialize($body));
    }

    public function testPreservesSlashesByDefault(): void
    {
        $serializer = new JsonMessageSerializer();
        $body = $serializer->serialize(['url' => 'https://example.com/orders/1']);
        $this->assertStringContainsString('https://example.com/orders/1', $body);
    }

    public function testPreservesUnicodeByDefault(): void
    {
        $serializer = new JsonMessageSerializer();
        $body = $serializer->serialize(['city' => 'München']);
        $this->assertStringContainsString('München', $body);
    }

    public function testCustomEncodeFlagsAreApplied(): void
    {
        $serializer = new JsonMessageSerializer(JSON_PRETTY_PRINT);
        $body = $serializer->serialize(['a' => 1]);

        $this->assertStringContainsString("\n", $body, 'pretty print flag should propagate');
    }

    public function testDeserializingNonObjectThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON body did not decode to an array/object');
        (new JsonMessageSerializer())->deserialize('"a plain string"');
    }

    public function testDeserializingInvalidJsonThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to decode JSON body');
        (new JsonMessageSerializer())->deserialize('{not-json');
    }

    public function testDeserializingEmptyBodyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new JsonMessageSerializer())->deserialize('');
    }

    public function testSerializeRejectsUnencodableData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to encode payload as JSON');

        $serializer = new JsonMessageSerializer();
        $resource = fopen('php://memory', 'r+');
        try {
            $serializer->serialize(['stream' => $resource]);
        } finally {
            fclose($resource);
        }
    }

    public function testZeroDecodeDepthRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JsonMessageSerializer(0, 0);
    }
}
