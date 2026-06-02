<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\TracePropagatorInterface;

/**
 * Native bridge between this package and the OpenTelemetry PHP SDK
 * (`open-telemetry/api`).
 *
 * This class is intentionally *soft-coupled* to OpenTelemetry: it duck-types
 * the SDK so that the package keeps working when `open-telemetry/api` isn't
 * installed. When the SDK is present, the propagator:
 *
 *   - reads the active OTel span context on **inject()** and writes a
 *     `traceparent` / `tracestate` header pair into the AMQP carrier
 *   - parses those same headers on **extract()** and reconstitutes a
 *     {@see TraceContext} that can be re-injected into outbound publishes
 *
 * The bridge stops short of starting OTel spans for every publish/consume —
 * that would mean shipping a span lifecycle helper and forcing an opinion
 * about span naming. Instead, applications wrap their own
 * `$tracer->spanBuilder('amqp.publish')->...->startSpan()` calls and rely on
 * this propagator to push the resulting span context across the broker.
 *
 * Drop-in registration in a service provider:
 *
 * ```php
 * $this->app->singleton(TracePropagatorInterface::class, function () {
 *     return new OpenTelemetryTracePropagator();
 * });
 * ```
 *
 * Or, if you keep your own `TextMapPropagatorInterface` around (custom B3,
 * Jaeger, etc.), pass it explicitly:
 *
 * ```php
 * new OpenTelemetryTracePropagator(
 *     \OpenTelemetry\API\Globals::propagator()
 * );
 * ```
 */
class OpenTelemetryTracePropagator implements TracePropagatorInterface
{
    /** @var object|null OpenTelemetry TextMapPropagatorInterface */
    protected $propagator;

    /** @var W3cTracePropagator Used as a fallback / final encoder. */
    protected $fallback;

    /**
     * @param object|null $propagator Any object implementing
     *                                `OpenTelemetry\Context\Propagation\TextMapPropagatorInterface`.
     *                                When null, the propagator is resolved from
     *                                `OpenTelemetry\API\Globals::propagator()` if available.
     */
    public function __construct($propagator = null)
    {
        $this->propagator = $propagator !== null
            ? $propagator
            : self::resolveGlobalPropagator();

        $this->fallback = new W3cTracePropagator();
    }

    /**
     * @return bool
     */
    public function hasNativePropagator(): bool
    {
        return is_object($this->propagator);
    }

    /**
     * {@inheritdoc}
     */
    public function inject(array $carrier, ?TraceContext $context = null): array
    {
        // When the caller hands us an explicit TraceContext, prefer it so the
        // chain stays deterministic. Otherwise pull the active OTel context.
        if ($context !== null) {
            return $this->fallback->inject($carrier, $context);
        }

        if (!$this->hasNativePropagator()) {
            return $this->fallback->inject($carrier);
        }

        $traceparent = $this->captureNativeTraceparent();
        if ($traceparent === null) {
            return $this->fallback->inject($carrier);
        }

        $carrier[TraceContext::TRACEPARENT_HEADER] = $traceparent['traceparent'];
        if (!empty($traceparent['tracestate'])) {
            $carrier[TraceContext::TRACESTATE_HEADER] = $traceparent['tracestate'];
        }

        return $carrier;
    }

    /**
     * {@inheritdoc}
     */
    public function extract(array $carrier): ?TraceContext
    {
        // Header format is identical to the W3C propagator's so the cheap
        // path is to defer to it. We only diverge from this when the OTel
        // SDK reports a different active context, but that's out of scope
        // for the propagator contract.
        return $this->fallback->extract($carrier);
    }

    /**
     * @return object|null
     */
    protected static function resolveGlobalPropagator()
    {
        $globals = '\\OpenTelemetry\\API\\Globals';
        if (!class_exists($globals) || !method_exists($globals, 'propagator')) {
            return null;
        }

        try {
            $propagator = call_user_func([$globals, 'propagator']);
        } catch (\Throwable $e) {
            return null;
        }

        return is_object($propagator) ? $propagator : null;
    }

    /**
     * Ask the OTel propagator to inject the current context into a temporary
     * carrier and return the `traceparent` / `tracestate` it produced.
     *
     * @return array{traceparent: string, tracestate: string}|null
     */
    protected function captureNativeTraceparent(): ?array
    {
        if (!$this->hasNativePropagator()) {
            return null;
        }

        $contextClass = '\\OpenTelemetry\\Context\\Context';
        if (!class_exists($contextClass) || !method_exists($contextClass, 'getCurrent')) {
            return null;
        }

        try {
            $current = call_user_func([$contextClass, 'getCurrent']);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_object($current)) {
            return null;
        }

        $carrier = [];

        try {
            // Most OTel propagators follow this signature:
            //     inject(mixed &$carrier, ?PropagationSetterInterface $setter, ContextInterface $context): void
            $this->propagator->inject($carrier, null, $current);
        } catch (\Throwable $e) {
            return null;
        }

        // Normalize: header names are case-insensitive in OTel carriers but
        // we store them lower-cased to match W3C.
        $normalized = [];
        foreach ((array) $carrier as $key => $value) {
            $normalized[strtolower((string) $key)] = (string) $value;
        }

        if (empty($normalized[TraceContext::TRACEPARENT_HEADER])) {
            return null;
        }

        return [
            'traceparent' => $normalized[TraceContext::TRACEPARENT_HEADER],
            'tracestate' => isset($normalized[TraceContext::TRACESTATE_HEADER])
                ? $normalized[TraceContext::TRACESTATE_HEADER]
                : '',
        ];
    }
}
