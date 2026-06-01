<?php

namespace Bschmitt\Amqp\Support;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Read and write AMQP application headers on messages.
 */
class MessageHeaders
{
    /**
     * @param AMQPMessage $message
     * @return array<string, mixed>
     */
    public static function toArray(AMQPMessage $message): array
    {
        $props = $message->get_properties();
        if (!isset($props['application_headers']) || !($props['application_headers'] instanceof AMQPTable)) {
            return [];
        }

        return $props['application_headers']->getNativeData();
    }
}
