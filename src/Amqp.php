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

    private $prefetch_size = 0;
    private $prefetch_count = 1;
    private $a_global = false;

    public function setPrefetchSize($prefetch_size) {
        $this->prefetch_size = $prefetch_size;
    }

    public function setPrefetchCount($prefetch_count) {
        $this->prefetch_count = $prefetch_count;
    }

    public function setAGlobal($a_global) {
        $this->a_global = $a_global;
    }

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

        $consumer->getChannel()->basic_qos($this->prefetch_size, $this->prefetch_count, $this->a_global);

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
