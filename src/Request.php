<?php

namespace Bschmitt\Amqp;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Channel\AMQPChannel;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Request extends Context
{

    /**
     * @var AMQPStreamConnection
     */
    protected $connection;

    /**
     * @var AMQPChannel
     */
    protected $channel;

    /**
     * @var array
     */
    protected $queueInfo;

    /**
     *
     */
    public function connect()
    {
        if ($this->getProperty('ssl_options')) {
            $this->connection = new AMQPSSLConnection(
                $this->getProperty('host'),
                $this->getProperty('port'),
                $this->getProperty('username'),
                $this->getProperty('password'),
                $this->getProperty('vhost'),
                $this->getProperty('ssl_options'),
                $this->getProperty('connect_options')
            );
        } else {
            $this->connection = new AMQPStreamConnection(
                $this->getProperty('host'),
                $this->getProperty('port'),
                $this->getProperty('username'),
                $this->getProperty('password'),
                $this->getProperty('vhost'),
                $this->getConnectOption('insist', false),
                $this->getConnectOption('login_method', 'AMQPLAIN'),
                $this->getConnectOption('login_response', null),
                $this->getConnectOption('locale', 3),
                $this->getConnectOption('connection_timeout', 3.0),
                $this->getConnectOption('read_write_timeout', 130),
                $this->getConnectOption('context', null),
                $this->getConnectOption('keepalive', false),
                $this->getConnectOption('heartbeat', 60),
                $this->getConnectOption('channel_rpc_timeout', 0.0),
                $this->getConnectOption('ssl_protocol', null)
            );
        }

        $this->channel = $this->connection->channel();
    }

    /**
     * @throws Exception\Configuration
     */
    public function setup()
    {
        $this->connect();

        $exchange = $this->getProperty('exchange');

        if (empty($exchange)) {
            throw new Exception\Configuration('Please check your settings, exchange is not defined.');
        }

        /*
            name: $exchange
            type: topic
            passive: false
            durable: true // the exchange will survive server restarts
            auto_delete: false //the exchange won't be deleted once the channel is closed.
        */
        $this->channel->exchange_declare(
            $exchange,
            $this->getProperty('exchange_type'),
            $this->getProperty('exchange_passive'),
            $this->getProperty('exchange_durable'),
            $this->getProperty('exchange_auto_delete'),
            $this->getProperty('exchange_internal'),
            $this->getProperty('exchange_nowait'),
            $this->getProperty('exchange_properties')
        );

        $queue = $this->getProperty('queue');

        if (!empty($queue) || $this->getProperty('queue_force_declare')) {
            /*
                name: $queue
                passive: false
                durable: true // the queue will survive server restarts
                exclusive: false // queue is deleted when connection closes
                auto_delete: false //the queue won't be deleted once the channel is closed.
                nowait: false // Doesn't wait on replies for certain things.
                parameters: array // Extra data, like high availability params
            */

            /** @var ['queue name', 'message count',] queueInfo */
            $this->queueInfo = $this->channel->queue_declare(
                $queue,
                $this->getProperty('queue_passive'),
                $this->getProperty('queue_durable'),
                $this->getProperty('queue_exclusive'),
                $this->getProperty('queue_auto_delete'),
                $this->getProperty('queue_nowait'),
                $this->getProperty('queue_properties')
            );

            $this->channel->queue_bind(
                $queue ?: $this->queueInfo[0],
                $exchange,
                $this->getProperty('routing')
            );
        }
        // clear at shutdown
        $this->connection->set_close_on_destruct(true);
    }

    /**
     * @return \PhpAmqpLib\Channel\AMQPChannel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     * @return AMQPStreamConnection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @return int
     */
    public function getQueueMessageCount()
    {
        if (is_array($this->queueInfo)) {
            return $this->queueInfo[1];
        }
        return 0;
    }

    /**
     * @param AMQPChannel          $channel
     * @param AMQPStreamConnection $connection
     */
    public static function shutdown(AMQPChannel $channel, AMQPStreamConnection $connection)
    {
        $channel->close();
        $connection->close();
    }
}
