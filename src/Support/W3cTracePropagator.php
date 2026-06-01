<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\TracePropagatorInterface;

/**
 * W3C Trace Context propagator for AMQP application headers.
 */
class W3cTracePropagator implements TracePropagatorInterface
{
    /**
     * {@inheritdoc}
     */
    public function inject(array $carrier, ?TraceContext $context = null): array
    {
        $ctx = $context !== null ? $context : TraceContext::generate();
        foreach ($ctx->toHeaders() as $key => $value) {
            $carrier[$key] = $value;
        }

        return $carrier;
    }

    /**
     * {@inheritdoc}
     */
    public function extract(array $carrier): ?TraceContext
    {
        if (!isset($carrier[TraceContext::TRACEPARENT_HEADER])) {
            return null;
        }

        $traceparent = (string) $carrier[TraceContext::TRACEPARENT_HEADER];
        $parts = explode('-', $traceparent);
        if (count($parts) < 4) {
            return null;
        }

        $traceId = $parts[1];
        $spanId = $parts[2];
        if (strlen($traceId) !== 32 || strlen($spanId) !== 16) {
            return null;
        }

        $traceState = isset($carrier[TraceContext::TRACESTATE_HEADER])
            ? (string) $carrier[TraceContext::TRACESTATE_HEADER]
            : null;

        return new TraceContext($traceId, $spanId, null, $traceState);
    }
}
