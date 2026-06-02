<?php

namespace Bschmitt\Amqp\Support;

/**
 * Decoded cross-service message with polyglot metadata headers.
 */
class InteropMessage
{
    /** @var string */
    public $body;

    /** @var string */
    public $messageType;

    /** @var string */
    public $sourceService;

    /** @var string */
    public $schemaVersion;

    /** @var string */
    public $contentType;

    /**
     * @var array<string, mixed>
     */
    public $headers;

    /**
     * @param string               $body
     * @param string               $messageType
     * @param string               $sourceService
     * @param string               $schemaVersion
     * @param string               $contentType
     * @param array<string, mixed> $headers
     */
    public function __construct(
        string $body,
        string $messageType,
        string $sourceService,
        string $schemaVersion,
        string $contentType,
        array $headers = []
    ) {
        $this->body = $body;
        $this->messageType = $messageType;
        $this->sourceService = $sourceService;
        $this->schemaVersion = $schemaVersion;
        $this->contentType = $contentType;
        $this->headers = $headers;
    }
}
