<?php

namespace Bschmitt\Amqp\Events;

/**
 * Dispatched immediately before a publish is sent to the broker.
 *
 * Listeners may mutate properties via reference if they implement their own
 * publish wrapper, but the package itself uses this event purely for
 * observation/logging.
 */
class MessagePublishing
{
    /** @var string */
    public $routing;

    /** @var mixed */
    public $message;

    /** @var array<string, mixed> */
    public $properties;

    /**
     * @param string               $routing
     * @param mixed                $message
     * @param array<string, mixed> $properties
     */
    public function __construct(string $routing, $message, array $properties)
    {
        $this->routing = $routing;
        $this->message = $message;
        $this->properties = $properties;
    }
}
