<?php

namespace Bschmitt\Amqp;

use Illuminate\Config\Repository;
use Closure;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

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
     * @throws \Exception
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
                qos: The consumer-prefetch make it possible to limit the number of unacknowledged messages on a channel (or connection) when consuming.
                                    Or, in other words, don't dispatch a new message to a worker
                                    until it has processed and acknowledged the previous one
                callback: A PHP Callback
            */

            $object = $this;

            if ($this->getProperty('qos')) {
                $this->getChannel()->basic_qos(
                    $this->getProperty('qos_prefetch_size'),
                    $this->getProperty('qos_prefetch_count'),
                    $this->getProperty('qos_a_global')
                );
            }

            $this->getChannel()->basic_consume(
                $queue,
                $this->getProperty('consumer_tag'),
                $this->getProperty('consumer_no_local'),
                $this->getProperty('consumer_no_ack'),
                $this->getProperty('consumer_exclusive'),
                $this->getProperty('consumer_nowait'),
                function ($message) use ($closure, $object) {
                    $closure($message, $object);
                }
            );

            // consume
            while (count($this->getChannel()->callbacks)) {
                $this->getChannel()->wait(
                    null,
                    !$this->getProperty('blocking'),
                    $this->getProperty('timeout') ? $this->getProperty('timeout') : 0
                );
            }
        } catch (\Exception $e) {
            if ($e instanceof Exception\Stop) {
                return true;
            }

            if ($e instanceof AMQPTimeoutException) {
                return true;
            }

            throw $e;
        }

        return true;
    }

    /**
     * Acknowledges a message
     *
     * @param AMQPMessage $message
     */
    public function acknowledge(AMQPMessage $message)
    {
        $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);

        if ($message->body === 'quit') {
            $message->delivery_info['channel']->basic_cancel($message->delivery_info['consumer_tag']);
        }
    }

    /**
     * Rejects a message and requeues it if wanted (default: false)
     *
     * @param AMQPMessage $message
     * @param bool    $requeue
     */
    public function reject(AMQPMessage $message, $requeue = false)
    {
        $message->delivery_info['channel']->basic_reject($message->delivery_info['delivery_tag'], $requeue);
    }

    /**
     * Stops consumer when no message is left
     *
     * @throws Exception\Stop
     */
    public function stopWhenProcessed()
    {
        if (--$this->messageCount <= 0) {
            throw new Exception\Stop();
        }
    }
}
