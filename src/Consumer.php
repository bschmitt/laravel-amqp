<?php namespace Bschmitt\Amqp;

use Illuminate\Config\Repository;
use Closure;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Consumer extends Request
{

    /**
     * @var int
     */
    protected $messageCount = 0;

    /**
     * @param string  $queue
     * @param Closure $closure
     * @return bool
     */
    public function consume($queue, Closure $closure)
    {
        try {

            $this->messageCount = $this->getQueueMessageCount();

            if (!$this->getProperty('persistent') && $this->messageCount == 0) {
                throw new Exception\Stop();
            }

            /*
                queue: Queue from where to get the messages
                consumer_tag: Consumer identifier
                no_local: Don't receive messages published by this consumer.
                no_ack: Tells the server if the consumer will acknowledge the messages.
                exclusive: Request exclusive consumer access, meaning only this consumer can access the queue
                nowait:
                callback: A PHP Callback
            */

            $object = $this;

            $this->getChannel()->basic_consume(
                $queue,
                $this->getProperty('consumer_tag'),
                false,
                false,
                false,
                false,
                function ($message) use ($closure, $object) {
                    $closure($message, $object);
                }
            );

            // consume
            while (count($this->getChannel()->callbacks)) {
                $this->getChannel()->wait(NULL, !$this->getProperty('blocking'), $this->getProperty('timeout') ? $this->getProperty('timeout') : 0);
            }

        } catch (\Exception $e) {

            if ($e instanceof Exception\Stop) {
                return true;
            }

            if ($e instanceof AMQPTimeoutException) {
                return true;
            }

            return false;
        }

        return true;
    }

    /**
     * @param Message $message
     */
    public function acknowledge($message)
    {
        $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);

        if ($message->body === 'quit') {
            $message->delivery_info['channel']->basic_cancel($message->delivery_info['consumer_tag']);
        }
    }

    /**
     * @throws Exception\Stop
     */
    public function stopWhenProcessed()
    {
        if (--$this->messageCount <= 0) {
            throw new Exception\Stop();
        }
    }

}
