<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\MessageSerializerInterface;
use InvalidArgumentException;

/**
 * Default JSON-based {@see MessageSerializerInterface} implementation.
 *
 * Uses `JSON_THROW_ON_ERROR` (available since PHP 7.3) so callers receive a
 * proper exception instead of having to check `json_last_error()`. Encoding
 * preserves slashes and unicode by default to keep wire bodies stable across
 * languages.
 */
class JsonMessageSerializer implements MessageSerializerInterface
{
    /** @var int */
    protected $encodeFlags;

    /** @var int */
    protected $decodeDepth;

    /**
     * @param int $encodeFlags Bitmask passed to `json_encode()`.
     *                         `JSON_THROW_ON_ERROR` is added automatically.
     * @param int $decodeDepth Maximum recursion depth (mirrors json_decode).
     */
    public function __construct(int $encodeFlags = 0, int $decodeDepth = 512)
    {
        if ($decodeDepth < 1) {
            throw new InvalidArgumentException('decodeDepth must be >= 1');
        }
        // Sane defaults that are friendly to URLs / non-ASCII strings.
        $defaults = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $this->encodeFlags = ($encodeFlags | $defaults | JSON_THROW_ON_ERROR);
        $this->decodeDepth = $decodeDepth;
    }

    /**
     * {@inheritdoc}
     */
    public function serialize(array $payload): string
    {
        try {
            return json_encode($payload, $this->encodeFlags);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Failed to encode payload as JSON: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deserialize(string $body): array
    {
        if ($body === '') {
            throw new InvalidArgumentException('Cannot deserialize an empty body');
        }

        try {
            $decoded = json_decode($body, true, $this->decodeDepth, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Failed to decode JSON body: '.$e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('JSON body did not decode to an array/object');
        }

        return $decoded;
    }

    /**
     * {@inheritdoc}
     */
    public function contentType(): string
    {
        return 'application/json';
    }
}
