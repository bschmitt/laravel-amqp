<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\TracePropagatorInterface;

/**
 * No-op propagator for applications that disable tracing.
 */
class NullTracePropagator implements TracePropagatorInterface
{
    /**
     * {@inheritdoc}
     */
    public function inject(array $carrier, ?TraceContext $context = null): array
    {
        return $carrier;
    }

    /**
     * {@inheritdoc}
     */
    public function extract(array $carrier): ?TraceContext
    {
        return null;
    }
}
