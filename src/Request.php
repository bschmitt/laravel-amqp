<?php namespace Bschmitt\Amqp;

use Illuminate\Config\Repository;
use PhpAmqpLib\Connection\AMQPStreamConnection;
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
        $this->connection = new AMQPStreamConnection(
            $this->getProperty('host'),
            $this->getProperty('port'),
            $this->getProperty('username'),
            $this->getProperty('password'),
            $this->getProperty('vhost')
        );

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
            false,
            true,
            false,
            false,
            false,
            $this->getProperty('exchange_properties')
        );

        $queue = $this->getProperty('queue');

        if (!empty($queue)) {

            /*
                name: $queue
                passive: false
                durable: true // the queue will survive server restarts
                exclusive: false // the queue can be accessed in other channels
                auto_delete: false //the queue won't be deleted once the channel is closed.
                nowait: false // Doesn't wait on replies for certain things.
                parameters: array // Extra data, like high availability params
            */

            $this->queueInfo = $this->channel->queue_declare(
                $queue,
                false,
                true,
                false,
                false,
                false,
                $this->getProperty('queue_properties')
            );

            $this->channel->queue_bind($queue, $exchange, $this->getProperty('binding'));

        }

        // clear at shutdown
        register_shutdown_function([get_class(), 'shutdown'], $this->channel, $this->connection);
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
 