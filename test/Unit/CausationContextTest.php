<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\CorrelationContext;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class CausationContextTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CorrelationContext::clear();
    }

    public function testApplyToPublishPropertiesIncludesCausationIdWhenSet(): void
    {
        CorrelationContext::set('corr-1');
        CorrelationContext::setCausation('msg-7');

        $properties = CorrelationContext::applyToPublishProperties([]);
        $this->assertSame('corr-1', $properties['correlation_id']);
        $this->assertSame('corr-1', $properties['application_headers'][CorrelationContext::HEADER]);
        $this->assertSame('msg-7', $properties['application_headers'][CorrelationContext::CAUSATION_HEADER]);
    }

    public function testInheritFromMessageCapturesMessageIdAsCausation(): void
    {
        $message = new AMQPMessage('{}', [
            'correlation_id' => 'corr-1',
            'message_id' => 'msg-99',
        ]);

        CorrelationContext::inheritFromMessage($message);

        $this->assertSame('corr-1', CorrelationContext::get());
        $this->assertSame('msg-99', CorrelationContext::getCausation());
    }

    public function testInheritFallsBackToCausationHeader(): void
    {
        $message = new AMQPMessage('{}', [
            'application_headers' => new AMQPTable([
                CorrelationContext::HEADER => 'corr-2',
                CorrelationContext::CAUSATION_HEADER => 'cause-77',
            ]),
        ]);

        CorrelationContext::inheritFromMessage($message);

        $this->assertSame('corr-2', CorrelationContext::get());
        $this->assertSame('cause-77', CorrelationContext::getCausation());
    }

    public function testClearResetsBothFields(): void
    {
        CorrelationContext::set('a');
        CorrelationContext::setCausation('b');
        CorrelationContext::clear();

        $this->assertNull(CorrelationContext::get());
        $this->assertNull(CorrelationContext::getCausation());
    }
}
