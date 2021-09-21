<?php

namespace Bschmitt\Amqp;

use Closure;
use Bschmitt\Amqp\Request;
use Bschmitt\Amqp\Message;
use Illuminate\Support\Facades\App;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Amqp
{
    protected static $batchMessages = [];

    /**
     * @param string $routing
     * @param mixed  $message
     * @param array  $properties
     *
     * @return bool|null
     *
     * @throws Exception\Configuration
     * @throws \Exception
     */
    public function publish($routing, $message, array $properties = []) : ?bool
    {
        $properties['routing'] = $routing;

        /* @var Publisher $publisher */

        $publisher = App::make(Publisher::class);

        $publisher
            ->mergeProperties($properties)
            ->setup();

        if (is_string($message)) {
            $message = new Message($message, ['content_type' => 'text/plain', 'delivery_mode' => 2]);
        }

        $mandatory = false;
        if (isset($properties['mandatory']) && $properties['mandatory'] == true) {
            $mandatory = true;
        }

        $return = $publisher->publish($routing, $message, $mandatory);
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        return $return;
    }

    /**
     * @param string $routing
     * @param mixed  $message
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
     * @throws \Exception
     */
    public function batchPublish(array $properties = [])
    {
        /* @var Publisher $publisher */
        $publisher = App::make(Publisher::class);
        $publisher
            ->mergeProperties($properties)
            ->setup();

        foreach(self::$batchMessages as $messageData) {
            if (is_string($messageData['message'])) {
                $messageData['message'] = new Message($messageData['message'], ['content_type' => 'text/plain', 'delivery_mode' => 2]);
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
    public function consume(string $queue, Closure $callback, array $properties = [])
    {
        $properties['queue'] = $queue;

        /* @var Consumer $consumer */
        $consumer = App::make(Consumer::class);
        $consumer
            ->mergeProperties($properties)
            ->setup();

        $consumer->consume($queue, $callback);
        Request::shutdown($consumer->getChannel(), $consumer->getConnection());
    }

    /**
     * @param string $body
     * @param array  $properties
     * @return Message
     */
    public function message(string $body, array $properties = []) : Message
    {
        return new Message($body, $properties);
    }
}
