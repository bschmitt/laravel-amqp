<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\TracePropagatorInterface;

/**
 * Bridge trace propagation to OpenTelemetry or other APM SDKs via callbacks.
 *
 * Example (pseudo-code with OTel):
 *   new CallbackTracePropagator(
 *       function (array $carrier, ?TraceContext $ctx) {
 *           // inject from active SpanContext into $carrier
 *           return $carrier;
 *       },
 *       function (array $carrier) {
 *           // return TraceContext built from $carrier or null
 *           return null;
 *       }
 *   );
 */
class CallbackTracePropagator implements TracePropagatorInterface
{
    /** @var callable */
    protected $injectCallback;

    /** @var callable */
    protected $extractCallback;

    /**
     * @param callable $inject  function (array $carrier, ?TraceContext $context): array
     * @param callable $extract function (array $carrier): ?TraceContext
     */
    public function __construct(callable $inject, callable $extract)
    {
        $this->injectCallback = $inject;
        $this->extractCallback = $extract;
    }

    /**
     * {@inheritdoc}
     */
    public function inject(array $carrier, ?TraceContext $context = null): array
    {
        return call_user_func($this->injectCallback, $carrier, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function extract(array $carrier): ?TraceContext
    {
        return call_user_func($this->extractCallback, $carrier);
    }
}
