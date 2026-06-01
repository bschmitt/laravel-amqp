<?php

namespace Bschmitt\Amqp\Support;

/**
 * Lightweight trace context for distributed tracing propagation.
 *
 * Carries W3C Trace Context identifiers without requiring the OpenTelemetry
 * PHP SDK. Pair with {@see W3cTracePropagator} or a custom
 * {@see \Bschmitt\Amqp\Contracts\TracePropagatorInterface} implementation
 * that bridges to your APM.
 */
class TraceContext
{
    public const TRACEPARENT_HEADER = 'traceparent';
    public const TRACESTATE_HEADER = 'tracestate';

    /** @var string */
    protected $traceId;

    /** @var string */
    protected $spanId;

    /** @var string|null */
    protected $parentSpanId;

    /** @var string|null */
    protected $traceState;

    /**
     * @param string      $traceId  32 hex chars.
     * @param string      $spanId   16 hex chars.
     * @param string|null $parentSpanId
     * @param string|null $traceState
     */
    public function __construct(string $traceId, string $spanId, ?string $parentSpanId = null, ?string $traceState = null)
    {
        $this->traceId = strtolower($traceId);
        $this->spanId = strtolower($spanId);
        $this->parentSpanId = $parentSpanId !== null ? strtolower($parentSpanId) : null;
        $this->traceState = $traceState;
    }

    /**
     * @return self
     */
    public static function generate(): self
    {
        return new self(self::randomHex(32), self::randomHex(16));
    }

    /**
     * @return string
     */
    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * @return string
     */
    public function spanId(): string
    {
        return $this->spanId;
    }

    /**
     * @return string|null
     */
    public function parentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    /**
     * @return string|null
     */
    public function traceState(): ?string
    {
        return $this->traceState;
    }

    /**
     * W3C traceparent value (version 00).
     *
     * @return string
     */
    public function traceparent(): string
    {
        $flags = '01';

        return sprintf('00-%s-%s-%s', $this->traceId, $this->spanId, $flags);
    }

    /**
     * Application headers suitable for AMQP messages.
     *
     * @return array<string, string>
     */
    public function toHeaders(): array
    {
        $headers = [
            self::TRACEPARENT_HEADER => $this->traceparent(),
        ];
        if ($this->traceState !== null && $this->traceState !== '') {
            $headers[self::TRACESTATE_HEADER] = $this->traceState;
        }

        return $headers;
    }

    /**
     * @param int $bytes Number of hex characters.
     * @return string
     */
    protected static function randomHex(int $bytes): string
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes((int) ($bytes / 2)));
        }

        return bin2hex(openssl_random_pseudo_bytes((int) ($bytes / 2)));
    }
}
