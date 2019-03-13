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
            $message = new Message($message, ['content_type' => $publisher->getProperty('content_type'), 'delivery_mode' => 2]);
        }

        $publisher->publish($routing, $message);
        Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    public function rpc(string $queue, string $message, $timeout = 0)
    {
        /* @var Publisher $publisher */
        $publisher = app()->make('Bschmitt\Amqp\Publisher');
        $publisher->connect();
        $publisher->getConnection()->set_close_on_destruct();
        $replyTo = $publisher->getChannel()->queue_declare(
            '',
            false,
            true,
            true,
            true
        );
        $replyTo = $replyTo[0];
        $publisher->getChannel()->queue_declare(
            $queue,
            $publisher->getProperty('queue_passive'),
            $publisher->getProperty('queue_durable'),
            $publisher->getProperty('queue_exclusive'),
            $publisher->getProperty('queue_auto_delete')
        );
        $response = false;
        $publisher->getChannel()->basic_consume(
            $replyTo,
            $publisher->getProperty('consumer_tag'),
            $publisher->getProperty('consumer_no_local'),
            $publisher->getProperty('consumer_no_ack'),
            $publisher->getProperty('consumer_exclusive'),
            $publisher->getProperty('consumer_nowait'),
            function ($message) use (&$response) {
                $response = $message;
            }
        );
        $publisher->getChannel()->queue_bind($queue, $publisher->getProperty('exchange'), $queue);
        $publisher->getChannel()->basic_publish(
            new \Bschmitt\Amqp\Message(
                $message,
                [
                    'content_type'  => $publisher->getProperty('content_type'),
                    'delivery_mode' => 1,
                    'reply_to'      => $replyTo,
                ]
            ),
            $publisher->getProperty('exchange'),
            $queue
        );
        $publisher->getChannel()->wait(null, false, $timeout);

        return $response ? json_decode($response->getBody()) : null;
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
