<?php

namespace Bschmitt\Amqp\Support;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Request-scoped correlation ID propagation for publish/consume flows.
 *
 * Stores the active correlation ID in a static slot (safe for typical PHP-FPM /
 * one-worker-per-process models). Use {@see ensure()} at the edge of a request
 * and {@see applyToPublishProperties()} before publishing so downstream consumers
 * and RPC replies can stitch logs together.
 */
class CorrelationContext
{
    public const HEADER = 'x-correlation-id';
    public const CAUSATION_HEADER = 'x-causation-id';

    /** @var string|null */
    protected static $correlationId;

    /** @var string|null */
    protected static $causationId;

    /**
     * @return string|null
     */
    public static function get(): ?string
    {
        return self::$correlationId;
    }

    /**
     * @param string|null $correlationId
     * @return void
     */
    public static function set(?string $correlationId): void
    {
        self::$correlationId = $correlationId;
    }

    /**
     * Clear the active correlation ID.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$correlationId = null;
        self::$causationId = null;
    }

    /**
     * @return string|null
     */
    public static function getCausation(): ?string
    {
        return self::$causationId;
    }

    /**
     * @param string|null $causationId
     * @return void
     */
    public static function setCausation(?string $causationId): void
    {
        self::$causationId = $causationId;
    }

    /**
     * Generate a new unique correlation ID.
     *
     * @return string
     */
    public static function generate(): string
    {
        return uniqid('corr_', true);
    }

    /**
     * Return the active ID or generate and remember a new one.
     *
     * @return string
     */
    public static function ensure(): string
    {
        if (self::$correlationId === null || self::$correlationId === '') {
            self::$correlationId = self::generate();
        }

        return self::$correlationId;
    }

    /**
     * Read correlation ID from an incoming message (property or header).
     *
     * @param AMQPMessage $message
     * @return void
     */
    public static function inheritFromMessage(AMQPMessage $message): void
    {
        $props = $message->get_properties();
        if (!empty($props['correlation_id'])) {
            self::$correlationId = (string) $props['correlation_id'];
        } else {
            $header = self::readHeader($message, self::HEADER);
            if ($header !== null && $header !== '') {
                self::$correlationId = (string) $header;
            }
        }

        // The inbound message_id becomes the causation_id of anything we
        // publish next — that's the canonical way to chain "this happened
        // because of that" across services.
        if (!empty($props['message_id'])) {
            self::$causationId = (string) $props['message_id'];
        } else {
            $causation = self::readHeader($message, self::CAUSATION_HEADER);
            if ($causation !== null && $causation !== '') {
                self::$causationId = (string) $causation;
            }
        }
    }

    /**
     * Merge correlation_id + application header into publish properties.
     *
     * @param array<string, mixed> $properties
     * @param string|null          $explicit Override the active context ID.
     * @return array<string, mixed>
     */
    public static function applyToPublishProperties(array $properties, ?string $explicit = null): array
    {
        $id = $explicit !== null ? $explicit : self::ensure();
        $properties['correlation_id'] = $id;

        $headers = (array) ($properties['application_headers'] ?? []);
        $headers[self::HEADER] = $id;

        if (self::$causationId !== null && self::$causationId !== '') {
            $headers[self::CAUSATION_HEADER] = self::$causationId;
        }

        $properties['application_headers'] = $headers;

        return $properties;
    }

    /**
     * @param AMQPMessage $message
     * @param string      $key
     * @return mixed|null
     */
    protected static function readHeader(AMQPMessage $message, string $key)
    {
        $props = $message->get_properties();
        if (!isset($props['application_headers']) || !($props['application_headers'] instanceof AMQPTable)) {
            return null;
        }

        $data = $props['application_headers']->getNativeData();

        return array_key_exists($key, $data) ? $data[$key] : null;
    }
}
