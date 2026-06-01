<?php

namespace Bschmitt\Amqp\Contracts;

use Bschmitt\Amqp\Support\TraceContext;

/**
 * Inject and extract distributed trace context on AMQP message headers.
 */
interface TracePropagatorInterface
{
    /**
     * Merge trace headers into an application_headers carrier.
     *
     * @param array<string, mixed> $carrier Existing headers (mutated in place by reference pattern).
     * @param TraceContext|null    $context When null, implementations may generate a new root span.
     * @return array<string, mixed>
     */
    public function inject(array $carrier, ?TraceContext $context = null): array;

    /**
     * Parse trace context from message headers.
     *
     * @param array<string, mixed> $carrier
     * @return TraceContext|null Null when no trace context is present.
     */
    public function extract(array $carrier): ?TraceContext;
}
