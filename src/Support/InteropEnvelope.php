<?php

namespace Bschmitt\Amqp\Support;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Cross-service / polyglot messaging envelope.
 *
 * Standard application headers let non-PHP consumers (Node, Go, Java, etc.)
 * route and version messages without sharing PHP classes:
 *
 *   x-message-type     — logical event name (e.g. orders.created)
 *   x-schema-version   — contract version (e.g. 1.0)
 *   x-source-service   — publishing service identifier
 *   content_type       — payload MIME type (default application/json)
 */
class InteropEnvelope
{
    public const HEADER_MESSAGE_TYPE = 'x-message-type';
    public const HEADER_SCHEMA_VERSION = 'x-schema-version';
    public const HEADER_SOURCE_SERVICE = 'x-source-service';

    /**
     * Merge interop headers into publish properties.
     *
     * @param array<string, mixed> $properties
     * @param string               $messageType
     * @param string               $sourceService
     * @param string               $schemaVersion
     * @param string               $contentType
     * @return array<string, mixed>
     */
    public static function applyToPublishProperties(
        array $properties,
        string $messageType,
        string $sourceService,
        string $schemaVersion = '1.0',
        string $contentType = 'application/json'
    ): array {
        $headers = (array) ($properties['application_headers'] ?? []);
        $headers[self::HEADER_MESSAGE_TYPE] = $messageType;
        $headers[self::HEADER_SCHEMA_VERSION] = $schemaVersion;
        $headers[self::HEADER_SOURCE_SERVICE] = $sourceService;
        $properties['application_headers'] = $headers;
        $properties['content_type'] = $contentType;
        $properties['type'] = $messageType;

        return $properties;
    }

    /**
     * @param AMQPMessage $message
     * @return InteropMessage
     */
    public static function fromMessage(AMQPMessage $message): InteropMessage
    {
        $headers = MessageHeaders::toArray($message);
        $props = $message->get_properties();

        $messageType = (string) ($headers[self::HEADER_MESSAGE_TYPE]
            ?? $props['type']
            ?? '');

        $sourceService = (string) ($headers[self::HEADER_SOURCE_SERVICE] ?? '');
        $schemaVersion = (string) ($headers[self::HEADER_SCHEMA_VERSION] ?? '1.0');
        $contentType = (string) ($props['content_type'] ?? 'application/octet-stream');

        return new InteropMessage(
            (string) $message->body,
            $messageType,
            $sourceService,
            $schemaVersion,
            $contentType,
            $headers
        );
    }

    /**
     * Decode JSON body when content type is application/json.
     *
     * @param InteropMessage $message
     * @return mixed
     */
    public static function decodePayload(InteropMessage $message)
    {
        if (strpos($message->contentType, 'application/json') === 0) {
            $decoded = json_decode($message->body, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $message->body;
        }

        return $message->body;
    }
}
