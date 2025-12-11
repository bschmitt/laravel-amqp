<?php

namespace Bschmitt\Amqp\Factories;

use Bschmitt\Amqp\Models\Message;
use PhpAmqpLib\Wire\AMQPTable;

class MessageFactory
{
    /**
     * Create a message from string or Message object
     *
     * @param string|Message $message
     * @param array $applicationHeaders
     * @param array $properties Additional message properties (priority, correlation_id, reply_to, etc.)
     * @return Message
     */
    public function create($message, array $applicationHeaders = [], array $properties = []): Message
    {
        if ($message instanceof Message) {
            // If message already exists, merge properties if provided
            if (!empty($properties)) {
                $this->applyProperties($message, $properties);
            }
            return $message;
        }

        $headers = [
            'content_type' => 'text/plain',
            'delivery_mode' => 2,
        ];

        // Apply standard message properties
        if (isset($properties['priority'])) {
            $headers['priority'] = (int) $properties['priority'];
        }

        if (isset($properties['correlation_id'])) {
            $headers['correlation_id'] = (string) $properties['correlation_id'];
        }

        if (isset($properties['reply_to'])) {
            $headers['reply_to'] = (string) $properties['reply_to'];
        }

        if (isset($properties['message_id'])) {
            $headers['message_id'] = (string) $properties['message_id'];
        }

        if (isset($properties['timestamp'])) {
            $headers['timestamp'] = (int) $properties['timestamp'];
        }

        if (isset($properties['type'])) {
            $headers['type'] = (string) $properties['type'];
        }

        if (isset($properties['user_id'])) {
            $headers['user_id'] = (string) $properties['user_id'];
        }

        if (isset($properties['app_id'])) {
            $headers['app_id'] = (string) $properties['app_id'];
        }

        if (isset($properties['expiration'])) {
            $headers['expiration'] = (string) $properties['expiration'];
        }

        if (isset($properties['content_type'])) {
            $headers['content_type'] = (string) $properties['content_type'];
        }

        if (isset($properties['content_encoding'])) {
            $headers['content_encoding'] = (string) $properties['content_encoding'];
        }

        if (isset($properties['delivery_mode'])) {
            $headers['delivery_mode'] = (int) $properties['delivery_mode'];
        }

        // Merge application headers with any headers from properties
        $mergedHeaders = $applicationHeaders;
        if (isset($properties['application_headers']) && is_array($properties['application_headers'])) {
            $mergedHeaders = array_merge($mergedHeaders, $properties['application_headers']);
        }

        if (!empty($mergedHeaders)) {
            $headers['application_headers'] = new AMQPTable($mergedHeaders);
        }

        return new Message($message, $headers);
    }

    /**
     * Apply properties to an existing message
     *
     * @param Message $message
     * @param array $properties
     * @return void
     */
    protected function applyProperties(Message $message, array $properties): void
    {
        if (isset($properties['priority'])) {
            $message->set('priority', (int) $properties['priority']);
        }

        if (isset($properties['correlation_id'])) {
            $message->set('correlation_id', (string) $properties['correlation_id']);
        }

        if (isset($properties['reply_to'])) {
            $message->set('reply_to', (string) $properties['reply_to']);
        }

        if (isset($properties['application_headers']) && is_array($properties['application_headers'])) {
            $existingHeaders = $message->get_properties();
            $existingAppHeaders = [];
            if (isset($existingHeaders['application_headers']) && $existingHeaders['application_headers'] instanceof AMQPTable) {
                $existingAppHeaders = $existingHeaders['application_headers']->getNativeData();
            }
            $mergedHeaders = array_merge($existingAppHeaders, $properties['application_headers']);
            $message->set('application_headers', new AMQPTable($mergedHeaders));
        }
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


