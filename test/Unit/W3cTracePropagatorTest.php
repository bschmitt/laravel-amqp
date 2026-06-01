<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\TraceContext;
use Bschmitt\Amqp\Support\W3cTracePropagator;
use Bschmitt\Amqp\Test\Support\BaseTestCase;

class W3cTracePropagatorTest extends BaseTestCase
{
    public function testInjectAddsTraceparentHeader(): void
    {
        $propagator = new W3cTracePropagator();
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

    public function testExtractParsesTraceparent(): void
    {
        $propagator = new W3cTracePropagator();
        $carrier = [
            TraceContext::TRACEPARENT_HEADER => '00-01234567890123456789012345678901-0123456789012345-01',
        ];
        $ctx = $propagator->extract($carrier);
        $this->assertInstanceOf(TraceContext::class, $ctx);
        $this->assertSame('01234567890123456789012345678901', $ctx->traceId());
        $this->assertSame('0123456789012345', $ctx->spanId());
    }

    public function testExtractReturnsNullForMissingHeader(): void
    {
        $propagator = new W3cTracePropagator();
        $this->assertNull($propagator->extract([]));
    }
}
