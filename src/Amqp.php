<?php

namespace Bschmitt\Amqp;

use Closure;
use Bschmitt\Amqp\Request;
use Bschmitt\Amqp\Message;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Amqp
{
    protected static array $batchMessages = [];

    /**
     * @param string $routing
     * @param mixed  $message
     * @param array  $properties
     */
    public function publish($routing, $message, array $properties = [])
    {
        $properties['routing'] = $routing;

        /* @var Publisher $publisher */
        $publisher = app()->make('Bschmitt\Amqp\Publisher');
        $publisher
            ->mergeProperties($properties)
            ->setup();

        if (is_string($message)) {
            $message = new Message($message, ['content_type' => 'text/plain', 'delivery_mode' => 2]);
        }

        $publisher->publish($routing, $message);
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * @param string $routing
     * @param        $message
     *
     */
    public function batchBasicPublish(string $routing, $message)
    {
        self::$batchMessages[] = [
            'routing' => $routing,
            'message' => $message,
        ];
    }

    /**
     * @param array $properties
     *
     * @throws Exception\Configuration
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function batchPublish(array $properties = [])
    {
        /* @var Publisher $publisher */
        $publisher = app()->make('Softonic\Amqp\Publisher');
        $publisher
            ->mergeProperties($properties)
            ->setup();

        $publishData = [];
        foreach(self::$batchMessages as $messageData) {
            if (is_string($messageData['message'])) {
                $messageData['message'] = new Message($messageData, ['content_type' => 'text/plain', 'delivery_mode' => 2]);
            }

            $publisher->batchBasicPublish($messageData['routing'], $messageData['message']);
        }

        $publisher->batchPublish();
        $this->forgetBatchedMessages();
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Remove the messages sent as a batch.
     */
    private function forgetBatchedMessages()
    {
        self::$batchMessages = [];
    }

    /**
     * @param string  $queue
     * @param Closure $callback
     * @param array   $properties
     * @throws Exception\Configuration
     */
    public function consume($queue, Closure $callback, $properties = [])
    {
        $properties['queue'] = $queue;

        /* @var Consumer $consumer */
        $consumer = app()->make('Bschmitt\Amqp\Consumer');
        $consumer
            ->mergeProperties($properties)
            ->setup();

        $consumer->consume($queue, $callback);
        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
    }

    /**
     * @param string $body
     * @param array  $properties
     * @return \Bschmitt\Amqp\Message
     */
    public function message($body, $properties = [])
    {
        return new Message($body, $properties);
    }
}
