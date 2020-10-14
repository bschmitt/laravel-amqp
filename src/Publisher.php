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

    /**
     * @param string $routing
     * @param Message $message
     */
    public function batchBasicPublish($routing, $message)
    {
        $this->getChannel()->batch_basic_publish($message, $this->getProperty('exchange'), $routing);
    }

    public function batchPublish()
    {
        $this->getChannel()->publish_batch();
    }
}
