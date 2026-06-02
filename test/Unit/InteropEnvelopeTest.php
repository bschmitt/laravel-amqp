<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\InteropEnvelope;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class InteropEnvelopeTest extends BaseTestCase
{
    public function testApplyToPublishPropertiesSetsHeaders(): void
    {
        $props = InteropEnvelope::applyToPublishProperties(
            [],
            'orders.created',
            'billing-service',
            '2.0'
        );

        $this->assertSame('orders.created', $props['type']);
        $this->assertSame('application/json', $props['content_type']);
        $this->assertSame('orders.created', $props['application_headers'][InteropEnvelope::HEADER_MESSAGE_TYPE]);
        $this->assertSame('billing-service', $props['application_headers'][InteropEnvelope::HEADER_SOURCE_SERVICE]);
        $this->assertSame('2.0', $props['application_headers'][InteropEnvelope::HEADER_SCHEMA_VERSION]);
    }

    public function testFromMessageReadsInteropHeaders(): void
    {
        $message = new AMQPMessage('{"id":1}', [
            'content_type' => 'application/json',
            'type' => 'orders.created',
            'application_headers' => new AMQPTable([
                InteropEnvelope::HEADER_SOURCE_SERVICE => 'shop-api',
                InteropEnvelope::HEADER_SCHEMA_VERSION => '1.0',
            ]),
        ]);

        $interop = InteropEnvelope::fromMessage($message);
        $this->assertSame('orders.created', $interop->messageType);
        $this->assertSame('shop-api', $interop->sourceService);
        $this->assertSame(['id' => 1], InteropEnvelope::decodePayload($interop));
    }
}
