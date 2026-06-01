<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\CorrelationContext;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class CorrelationContextTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        CorrelationContext::clear();
        parent::tearDown();
    }

    public function testEnsureGeneratesAndRemembersId(): void
    {
        $first = CorrelationContext::ensure();
        $second = CorrelationContext::ensure();
        $this->assertSame($first, $second);
        $this->assertNotSame('', $first);
    }

    public function testApplyToPublishPropertiesSetsCorrelationAndHeader(): void
    {
        CorrelationContext::set('test-corr');
        $props = CorrelationContext::applyToPublishProperties([]);
        $this->assertSame('test-corr', $props['correlation_id']);
        $this->assertSame('test-corr', $props['application_headers'][CorrelationContext::HEADER]);
    }

    public function testInheritFromMessageReadsProperty(): void
    {
        $message = new AMQPMessage('body', ['correlation_id' => 'from-msg']);
        CorrelationContext::inheritFromMessage($message);
        $this->assertSame('from-msg', CorrelationContext::get());
    }

    public function testInheritFromMessageReadsHeader(): void
    {
        $message = new AMQPMessage('body', [
            'application_headers' => new AMQPTable([
                CorrelationContext::HEADER => 'from-header',
            ]),
        ]);
        CorrelationContext::inheritFromMessage($message);
        $this->assertSame('from-header', CorrelationContext::get());
    }
}
