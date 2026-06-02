<?php

namespace Bschmitt\Amqp\Events;

/**
 * Dispatched after a successful publish (including publisher-confirm acks
 * if enabled).
 */
class MessagePublished
{
    /** @var string */
    public $routing;

    /** @var mixed */
    public $message;

    /** @var array<string, mixed> */
    public $properties;

    /** @var bool|null */
    public $result;

    /**
     * @param string               $routing
     * @param mixed                $message
     * @param array<string, mixed> $properties
     * @param bool|null            $result Return value from the publish call.
     */
    public function __construct(string $routing, $message, array $properties, $result)
    {
        $this->routing = $routing;
        $this->message = $message;
        $this->properties = $properties;
        $this->result = $result;
    }
}
