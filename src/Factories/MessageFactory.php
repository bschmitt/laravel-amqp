<?php

namespace Bschmitt\Amqp\Factories;

use Bschmitt\Amqp\Message;
use PhpAmqpLib\Wire\AMQPTable;

class MessageFactory
{
    /**
     * Create a message from string or Message object
     *
     * @param string|Message $message
     * @param array $applicationHeaders
     * @return Message
     */
    public function create($message, array $applicationHeaders = []): Message
    {
        if ($message instanceof Message) {
            return $message;
        }

        $headers = [
            'content_type' => 'text/plain',
            'delivery_mode' => 2,
        ];

        if (!empty($applicationHeaders)) {
            $headers['application_headers'] = new AMQPTable($applicationHeaders);
        }

        return new Message($message, $headers);
    }

    /**
     * Create a message with custom properties
     *
     * @param string $body
     * @param array $properties
     * @return Message
     */
    public function createWithProperties(string $body, array $properties = []): Message
    {
        return new Message($body, $properties);
    }
}


