<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Models\Message;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;

class MessagePropertiesTest extends TestCase
{
    protected $messageFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->messageFactory = new MessageFactory();
    }

    public function testCreateMessageWithPriority()
    {
        $message = $this->messageFactory->create('test message', [], [
            'priority' => 10
        ]);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertEquals(10, $message->getPriority());
    }

    public function testCreateMessageWithCorrelationId()
    {
        $correlationId = 'corr-12345';
        $message = $this->messageFactory->create('test message', [], [
            'correlation_id' => $correlationId
        ]);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertEquals($correlationId, $message->getCorrelationId());
    }

    public function testCreateMessageWithReplyTo()
    {
        $replyTo = 'reply-queue';
        $message = $this->messageFactory->create('test message', [], [
            'reply_to' => $replyTo
        ]);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertEquals($replyTo, $message->getReplyTo());
    }

    public function testCreateMessageWithAllProperties()
    {
        $message = $this->messageFactory->create('test message', [], [
            'priority' => 5,
            'correlation_id' => 'corr-123',
            'reply_to' => 'reply-queue',
            'message_id' => 'msg-456',
            'type' => 'test-type'
        ]);

        $this->assertEquals(5, $message->getPriority());
        $this->assertEquals('corr-123', $message->getCorrelationId());
        $this->assertEquals('reply-queue', $message->getReplyTo());
        
        $properties = $message->get_properties();
        $this->assertEquals('msg-456', $properties['message_id']);
        $this->assertEquals('test-type', $properties['type']);
    }

    public function testMessageSetPriority()
    {
        $message = $this->messageFactory->create('test message');
        
        $message->setPriority(15);
        $this->assertEquals(15, $message->getPriority());
        
        // Test clamping to max 255
        $message->setPriority(300);
        $this->assertEquals(255, $message->getPriority());
        
        // Test clamping to min 0
        $message->setPriority(-5);
        $this->assertEquals(0, $message->getPriority());
    }

    public function testMessageSetCorrelationId()
    {
        $message = $this->messageFactory->create('test message');
        
        $message->setCorrelationId('test-correlation-id');
        $this->assertEquals('test-correlation-id', $message->getCorrelationId());
    }

    public function testMessageSetReplyTo()
    {
        $message = $this->messageFactory->create('test message');
        
        $message->setReplyTo('reply-queue-name');
        $this->assertEquals('reply-queue-name', $message->getReplyTo());
    }

    public function testMessageSetHeader()
    {
        $message = $this->messageFactory->create('test message');
        
        $message->setHeader('x-custom-header', 'custom-value');
        $this->assertEquals('custom-value', $message->getHeader('x-custom-header'));
    }

    public function testMessageSetMultipleHeaders()
    {
        $message = $this->messageFactory->create('test message');
        
        $message->setHeaders([
            'x-header-1' => 'value-1',
            'x-header-2' => 'value-2',
            'x-header-3' => 'value-3'
        ]);
        
        $headers = $message->getHeaders();
        $this->assertEquals('value-1', $headers['x-header-1']);
        $this->assertEquals('value-2', $headers['x-header-2']);
        $this->assertEquals('value-3', $headers['x-header-3']);
    }

    public function testMessageGetHeaderWithDefault()
    {
        $message = $this->messageFactory->create('test message');
        
        $this->assertNull($message->getHeader('non-existent'));
        $this->assertEquals('default', $message->getHeader('non-existent', 'default'));
    }

    public function testMessageRemoveHeader()
    {
        $message = $this->messageFactory->create('test message');
        
        $message->setHeader('x-test', 'value');
        $this->assertEquals('value', $message->getHeader('x-test'));
        
        $message->removeHeader('x-test');
        $this->assertNull($message->getHeader('x-test'));
    }

    public function testMessageHeadersMergeWithApplicationHeaders()
    {
        $message = $this->messageFactory->create('test message', [
            'x-existing' => 'existing-value'
        ], [
            'application_headers' => [
                'x-new' => 'new-value'
            ]
        ]);
        
        $headers = $message->getHeaders();
        $this->assertEquals('existing-value', $headers['x-existing']);
        $this->assertEquals('new-value', $headers['x-new']);
    }

    public function testMessageFactoryAppliesPropertiesToExistingMessage()
    {
        $message = $this->messageFactory->create('test message');
        
        // Apply properties to existing message
        $message = $this->messageFactory->create($message, [], [
            'priority' => 20,
            'correlation_id' => 'updated-correlation'
        ]);
        
        $this->assertEquals(20, $message->getPriority());
        $this->assertEquals('updated-correlation', $message->getCorrelationId());
    }

    public function testMessagePriorityRange()
    {
        $message = $this->messageFactory->create('test message');
        
        // Test valid range
        $message->setPriority(0);
        $this->assertEquals(0, $message->getPriority());
        
        $message->setPriority(255);
        $this->assertEquals(255, $message->getPriority());
        
        $message->setPriority(128);
        $this->assertEquals(128, $message->getPriority());
    }

    public function testMessageGetAllHeaders()
    {
        $message = $this->messageFactory->create('test message', [
            'header1' => 'value1',
            'header2' => 'value2'
        ]);
        
        $headers = $message->getHeaders();
        $this->assertIsArray($headers);
        $this->assertEquals('value1', $headers['header1']);
        $this->assertEquals('value2', $headers['header2']);
    }

    public function testMessageHeadersEmptyInitially()
    {
        $message = $this->messageFactory->create('test message');
        
        $headers = $message->getHeaders();
        $this->assertIsArray($headers);
        $this->assertEmpty($headers);
    }
}

