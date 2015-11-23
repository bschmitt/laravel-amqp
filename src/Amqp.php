<?php namespace Bschmitt\Amqp;

use App;
use Closure;
use Bschmitt\Amqp\Request;
use Bschmitt\Amqp\Message;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Amqp
{

    /**
     * @param string $binding
     * @param mixed  $message
     * @param array  $properties
     */
    public function publish($binding, $message, array $properties = [])
    {
        /* @var Publisher $publisher */
        $publisher = App::make('Bschmitt\Amqp\Publisher');
        $publisher
            ->mergeProperties($properties)
            ->setup();

        if (is_string($message)) {
            $message = new Message($message, ['content_type' => 'text/plain', 'delivery_mode' => 2]);
        }

        $publisher->publish($binding, $message);
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
        $consumer = App::make('Bschmitt\Amqp\Consumer');
        $consumer
            ->mergeProperties($properties)
            ->setup();

        $consumer->consume($queue, $callback);
    }

}