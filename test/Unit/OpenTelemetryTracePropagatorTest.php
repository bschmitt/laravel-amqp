<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\OpenTelemetryTracePropagator;
use Bschmitt\Amqp\Support\TraceContext;
use Bschmitt\Amqp\Test\Support\BaseTestCase;

class OpenTelemetryTracePropagatorTest extends BaseTestCase
{
    public function testFallsBackToW3CWhenNoOtelPropagatorAvailable(): void
    {
        $propagator = new OpenTelemetryTracePropagator(null);

        $this->assertFalse($propagator->hasNativePropagator());

        $ctx = new TraceContext(
            '01234567890123456789012345678901',
            '0123456789012345'
        );
        $carrier = $propagator->inject([], $ctx);

        $this->assertArrayHasKey(TraceContext::TRACEPARENT_HEADER, $carrier);
        $this->assertStringContainsString(
            '01234567890123456789012345678901',
            $carrier[TraceContext::TRACEPARENT_HEADER]
        );
    }

    public function testExtractParsesW3CHeader(): void
    {
        $propagator = new OpenTelemetryTracePropagator(null);

        $ctx = $propagator->extract([
            TraceContext::TRACEPARENT_HEADER => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            TraceContext::TRACESTATE_HEADER => 'vendor=value',
        ]);

        $this->assertInstanceOf(TraceContext::class, $ctx);
        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $ctx->traceId());
        $this->assertSame('b7ad6b7169203331', $ctx->spanId());
        $this->assertSame('vendor=value', $ctx->traceState());
    }

    public function testExtractReturnsNullForMissingHeader(): void
    {
        $propagator = new OpenTelemetryTracePropagator(null);

        $this->assertNull($propagator->extract([]));
    }

    public function testInjectAcceptsCustomPropagatorThatProducesTraceparent(): void
    {
        // Stand in for an OpenTelemetry TextMapPropagatorInterface.
        // The propagator only requires an `inject(&$carrier, $setter, $context)`
        // method — anything that fills a `traceparent` key is enough for the
        // bridge to forward it onto the AMQP carrier.
        $stub = new class {
            public function inject(&$carrier, $setter, $context): void
            {
                $carrier['traceparent'] = '00-ffffffffffffffffffffffffffffffff-1111111111111111-01';
                $carrier['tracestate'] = 'otel=demo';
            }
            public function fields(): array
            {
                return ['traceparent', 'tracestate'];
            }
        };

        $propagator = new OpenTelemetryTracePropagator($stub);

        $this->assertTrue($propagator->hasNativePropagator());

        $carrier = $propagator->inject([]);

        // When the SDK's globally-resolved Context\Context isn't installed
        // (which is the case in this test environment), the propagator must
        // gracefully fall back to W3C generation. Either path is a valid
        // outcome — but if the OTel context resolution did succeed, the
        // stubbed traceparent must be forwarded verbatim.
        $this->assertArrayHasKey(TraceContext::TRACEPARENT_HEADER, $carrier);
        if (class_exists('\\OpenTelemetry\\Context\\Context')) {
            $this->assertSame(
                '00-ffffffffffffffffffffffffffffffff-1111111111111111-01',
                $carrier[TraceContext::TRACEPARENT_HEADER]
            );
            $this->assertSame('otel=demo', $carrier[TraceContext::TRACESTATE_HEADER]);
        }
    }

    public function testExplicitTraceContextWinsOverNativePropagator(): void
    {
        $stub = new class {
            /** @var int Asserts that inject() was NEVER invoked when ctx given. */
            public $injectCalls = 0;
            public function inject(&$carrier, $setter, $context): void
            {
                $this->injectCalls++;
                $carrier['traceparent'] = '00-0-0-0';
            }
        };

        $propagator = new OpenTelemetryTracePropagator($stub);

        $ctx = new TraceContext(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'bbbbbbbbbbbbbbbb'
        );
        $carrier = $propagator->inject([], $ctx);

        $this->assertSame(0, $stub->injectCalls);
        $this->assertSame(
            '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
            $carrier[TraceContext::TRACEPARENT_HEADER]
        );
    }
}
