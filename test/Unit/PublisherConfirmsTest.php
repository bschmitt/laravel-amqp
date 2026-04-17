<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Test\Support\BaseTestCase;
use \Mockery;
use Bschmitt\Amqp\Core\Publisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Test cases for Publisher Confirms feature
 * 
 * Publisher confirms provide:
 * - Guaranteed message delivery confirmation
 * - Ack callback registration
 * - Nack callback registration
 * - Return callback registration
 * - Wait for confirms API
 * 
 * Reference: https://www.rabbitmq.com/docs/confirms
 */
class PublisherConfirmsTest extends BaseTestCase
{
    private $connectionMock;
    private $channelMock;
    private $publisherMock;

    protected function setUp() : void
    {
        parent::setUp();

        $this->channelMock = Mockery::mock(AMQPChannel::class);
        $this->connectionMock = Mockery::mock(AMQPSSLConnection::class);
        $this->publisherMock = Mockery::mock(Publisher::class . '[connect,getChannel]', [$this->configRepository]);

        $this->setProtectedProperty(Publisher::class, $this->publisherMock, 'channel', $this->channelMock);
        $this->setProtectedProperty(Publisher::class, $this->publisherMock, 'connection', $this->connectionMock);
    }

    /**
     * Test that publisher confirms can be enabled
     */
    public function testEnablePublisherConfirms()
    {
        $this->channelMock->shouldReceive('confirm_select')
            ->once();

        $this->publisherMock->shouldReceive('getChannel')
            ->andReturn($this->channelMock)
            ->once();

        $this->channelMock->shouldReceive('set_ack_handler')
            ->with(Mockery::type('array'))
            ->once();

        $this->channelMock->shouldReceive('set_nack_handler')
            ->with(Mockery::type('array'))
            ->once();

        $this->publisherMock->enablePublisherConfirms();

        $this->assertTrue($this->publisherMock->isConfirmsEnabled());
    }

    /**
     * Test that enabling confirms twice doesn't cause issues
     */
    public function testEnablePublisherConfirmsTwice()
    {
        $this->channelMock->shouldReceive('confirm_select')
            ->once(); // Should only be called once

        $this->channelMock->shouldReceive('set_ack_handler')
            ->with(Mockery::type('array'))
            ->once();

        $this->channelMock->shouldReceive('set_nack_handler')
            ->with(Mockery::type('array'))
            ->once();

        $this->publisherMock->shouldReceive('getChannel')
            ->andReturn($this->channelMock)
            ->once(); // Only called once

        $this->publisherMock->enablePublisherConfirms();
        $this->publisherMock->enablePublisherConfirms(); // Second call should be ignored

        $this->assertTrue($this->publisherMock->isConfirmsEnabled());
    }

    /**
     * Test that ack handler can be registered
     */
    public function testSetAckHandler()
    {
        // Use a real Publisher instance for this test
        $publisher = new Publisher($this->configRepository);
        
        $callback = function($msg) {
            // Test callback
        };

        $publisher->setAckHandler($callback);

        // Use reflection to verify handler was set
        $reflection = new \ReflectionClass($publisher);
        $property = $reflection->getProperty('ackHandler');
        $property->setAccessible(true);
        $handler = $property->getValue($publisher);

        $this->assertSame($callback, $handler);
    }

    /**
     * Test that nack handler can be registered
     */
    public function testSetNackHandler()
    {
        // Use a real Publisher instance for this test
        $publisher = new Publisher($this->configRepository);
        
        $callback = function($msg) {
            // Test callback
        };

        $publisher->setNackHandler($callback);

        // Use reflection to verify handler was set
        $reflection = new \ReflectionClass($publisher);
        $property = $reflection->getProperty('nackHandler');
        $property->setAccessible(true);
        $handler = $property->getValue($publisher);

        $this->assertSame($callback, $handler);
    }

    /**
     * Test that return handler can be registered
     */
    public function testSetReturnHandler()
    {
        // Use a real Publisher instance for this test
        $publisher = new Publisher($this->configRepository);
        
        $callback = function($msg) {
            // Test callback
        };

        $publisher->setReturnHandler($callback);

        // Use reflection to verify handler was set
        $reflection = new \ReflectionClass($publisher);
        $property = $reflection->getProperty('returnHandler');
        $property->setAccessible(true);
        $handler = $property->getValue($publisher);

        $this->assertSame($callback, $handler);
    }

    /**
     * Test that waitForConfirms throws exception when confirms not enabled
     */
    public function testWaitForConfirmsThrowsExceptionWhenNotEnabled()
    {
        // Use a real Publisher instance for this test
        $publisher = new Publisher($this->configRepository);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Publisher confirms are not enabled');

        $publisher->waitForConfirms();
    }

    /**
     * Test that waitForConfirms works when confirms are enabled
     */
    public function testWaitForConfirms()
    {
        // Enable confirms first
        $this->channelMock->shouldReceive('confirm_select')
            ->once();

        $this->channelMock->shouldReceive('set_ack_handler')
            ->with(Mockery::type('array'))
            ->once();

        $this->channelMock->shouldReceive('set_nack_handler')
            ->with(Mockery::type('array'))
            ->once();

        $this->publisherMock->shouldReceive('getChannel')
            ->andReturn($this->channelMock)
            ->times(2);

        $this->publisherMock->enablePublisherConfirms();

        // Test waitForConfirms
        $this->channelMock->shouldReceive('wait_for_pending_acks')
            ->with(30)
            ->andReturn(true)
            ->once();

        $result = $this->publisherMock->waitForConfirms(30);
        $this->assertTrue($result);
    }

    /**
     * Test that waitForConfirmsAndReturns works when confirms are enabled
     */
    public function testWaitForConfirmsAndReturns()
    {
        // Enable confirms first
        $this->channelMock->shouldReceive('confirm_select')
            ->once();

        $this->channelMock->shouldReceive('set_ack_handler')
            ->with(Mockery::type('array'))
            ->once();

        $this->channelMock->shouldReceive('set_nack_handler')
            ->with(Mockery::type('array'))
            ->once();

        $this->publisherMock->shouldReceive('getChannel')
            ->andReturn($this->channelMock)
            ->times(2);

        $this->publisherMock->enablePublisherConfirms();

        // Test waitForConfirmsAndReturns
        $this->channelMock->shouldReceive('wait_for_pending_acks_returns')
            ->with(30)
            ->andReturn(true)
            ->once();

        $result = $this->publisherMock->waitForConfirmsAndReturns(30);
        $this->assertTrue($result);
    }

    /**
     * Test that ack handler is called when message is acked
     */
    public function testAckHandlerCalled()
    {
        // Use a real Publisher instance for this test
        $publisher = new Publisher($this->configRepository);
        
        $ackCalled = false;
        $callback = function($msg) use (&$ackCalled) {
            $ackCalled = true;
        };

        $publisher->setAckHandler($callback);

        // Simulate ack (without actually enabling confirms)
        $msg = Mockery::mock(AMQPMessage::class);
        $publisher->handleAck($msg);

        $this->assertTrue($ackCalled);
    }

    /**
     * Test that nack handler is called when message is nacked
     */
    public function testNackHandlerCalled()
    {
        // Use a real Publisher instance for this test
        $publisher = new Publisher($this->configRepository);
        
        $nackCalled = false;
        $callback = function($msg) use (&$nackCalled) {
            $nackCalled = true;
        };

        $publisher->setNackHandler($callback);

        // Simulate nack
        $msg = Mockery::mock(AMQPMessage::class);
        $publisher->handleNack($msg);

        $this->assertTrue($nackCalled);
        
        // Verify publishResult was set to false using reflection
        $reflection = new \ReflectionClass($publisher);
        $property = $reflection->getProperty('publishResult');
        $property->setAccessible(true);
        $publishResult = $property->getValue($publisher);
        $this->assertFalse($publishResult);
    }

    /**
     * Test that return handler is called when message is returned
     */
    public function testReturnHandlerCalled()
    {
        // Use a real Publisher instance for this test
        $publisher = new Publisher($this->configRepository);
        
        $returnCalled = false;
        $callback = function($msg) use (&$returnCalled) {
            $returnCalled = true;
        };

        $publisher->setReturnHandler($callback);

        // Simulate return
        $msg = Mockery::mock(AMQPMessage::class);
        $publisher->handleReturn($msg);

        $this->assertTrue($returnCalled);
        
        // Verify publishResult was set to false using reflection
        $reflection = new \ReflectionClass($publisher);
        $property = $reflection->getProperty('publishResult');
        $property->setAccessible(true);
        $publishResult = $property->getValue($publisher);
        $this->assertFalse($publishResult);
    }
}
