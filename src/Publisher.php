<?php

namespace Bschmitt\Amqp;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Publisher extends Request
{
    /**
    * The result of the call to basic_publish. 
    * Assumed to be successful unless overridden with 
    * a basic.return or basic.nack. Only valid when in 
    * confirm_select mode
    */
    private $publish_result = null;

    /**
     * @param string  $routing
     * @param Message $message
     * @throws Exception\Configuration
     */
    public function publish($routing, $message, $mandatory = false)
    {
	$this->publish_result = true;

	if ($mandatory === true) {
            $this->getChannel()->confirm_select();
            $this->getChannel()->set_nack_handler([$this, 'nack']);
            $this->getChannel()->set_return_listener([$this, 'return']);
        }

        $this->getChannel()->basic_publish($message, $this->getProperty('exchange'), $routing, $mandatory);
        $mandatory == true && $this->getChannel()->wait_for_pending_acks_returns(30);
    }

    /*
    * @param Object Message object
    */
    public function nack($msg) {
    	$this->publish_result = false;
    }

    /*
    * @param Object Message object
    */
    public function return($msg) {
    	$this->publish_result = false;
    }
}
