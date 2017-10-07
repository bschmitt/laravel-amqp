<?php

namespace Bschmitt\Amqp;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Publisher extends Request
{

    /**
     * @param string  $routing
     * @param Message $message
     * @throws Exception\Configuration
     */
    public function publish($routing, $message)
    {
        $this->getChannel()->basic_publish($message, $this->getProperty('exchange'), $routing);
    }
}
