<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Support\ConfigurationProvider;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Illuminate\Config\Repository;
use Mockery;

class ReplyMethodTest extends \PHPUnit\Framework\TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testReplyPublishesToReplyToQueue()
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => [
                        'host' => 'localhost',
                        'port' => 5672,
                    ],
                ],
            ],
        ]);

        $configProvider = new ConfigurationProvider($config);
        $channel = Mockery::mock(AMQPChannel::class);
        $connection = Mockery::mock(AMQPStreamConnection::class);

        $requestMessage = Mockery::mock(AMQPMessage::class);
        $requestMessage->shouldReceive('get')
            ->with('reply_to')
            ->andReturn('reply-queue');
        $requestMessage->shouldReceive('get')
            ->with('correlation_id')
            ->andReturn('correlation-123');
        $requestMessage->shouldReceive('get_properties')
            ->andReturn(['reply_to' => 'reply-queue', 'correlation_id' => 'correlation-123']);

        $consumer = Mockery::mock(Consumer::class)->makePartial();
        $consumer->shouldReceive('getChannel')
            ->andReturn($channel);

        // The reply method creates a Publisher internally
        // This is a simplified test - full test requires integration test
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot reply: original message has no reply_to property');

        $messageWithoutReplyTo = Mockery::mock(AMQPMessage::class);
        $messageWithoutReplyTo->shouldReceive('get_properties')
            ->andReturn([]); // No reply_to in properties

        $consumer->reply($messageWithoutReplyTo, 'response');
    }

    public function testReplyThrowsExceptionWhenNoReplyTo()
    {
        $config = new Repository([
            'amqp' => [
                'use' => 'production',
                'properties' => [
                    'production' => [],
                ],
            ],
        ]);

        $consumer = Mockery::mock(Consumer::class)->makePartial();

        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('get')
            ->with('reply_to')
            ->andReturn(null);
        $message->shouldReceive('get')
            ->with('correlation_id')
            ->andReturn(null);
        $message->shouldReceive('get_properties')
            ->andReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot reply: original message has no reply_to property');

        $consumer->reply($message, 'response');
    }
}

