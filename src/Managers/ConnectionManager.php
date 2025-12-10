<?php

namespace Bschmitt\Amqp\Managers;

use Bschmitt\Amqp\Contracts\ConnectionManagerInterface;
use Bschmitt\Amqp\Contracts\ConfigurationProviderInterface;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Channel\AMQPChannel;

class ConnectionManager implements ConnectionManagerInterface
{
    /**
     * @var ConfigurationProviderInterface
     */
    protected $config;

    /**
     * @var AMQPStreamConnection|null
     */
    protected $connection;

    /**
     * @var AMQPChannel|null
     */
    protected $channel;

    /**
     * @param ConfigurationProviderInterface $config
     */
    public function __construct(ConfigurationProviderInterface $config)
    {
        $this->config = $config;
    }

    /**
     * @return void
     */
    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $host = $this->config->getProperty('host');
        $port = (int) $this->config->getProperty('port', 5672);
        $username = $this->config->getProperty('username', 'guest');
        $password = $this->config->getProperty('password', 'guest');
        $vhost = $this->config->getProperty('vhost', '/');

        if ($this->config->getProperty('ssl_options')) {
            $this->connection = new AMQPSSLConnection(
                $host,
                $port,
                $username,
                $password,
                $vhost,
                $this->config->getProperty('ssl_options', []),
                $this->config->getProperty('connect_options', [])
            );
        } else {
            $this->connection = new AMQPStreamConnection(
                $host,
                $port,
                $username,
                $password,
                $vhost,
                $this->config->getConnectOption('insist', false),
                $this->config->getConnectOption('login_method', 'AMQPLAIN'),
                $this->config->getConnectOption('login_response', null),
                $this->config->getConnectOption('locale', 3),
                $this->config->getConnectOption('connection_timeout', 3.0),
                $this->config->getConnectOption('read_write_timeout', 130),
                $this->config->getConnectOption('context', null),
                $this->config->getConnectOption('keepalive', false),
                $this->config->getConnectOption('heartbeat', 60),
                $this->config->getConnectOption('channel_rpc_timeout', 0.0),
                $this->config->getConnectOption('ssl_protocol', null)
            );
        }

        $this->channel = $this->connection->channel();
        $this->connection->set_close_on_destruct(true);
    }

    /**
     * @return AMQPChannel
     */
    public function getChannel(): AMQPChannel
    {
        if (!$this->channel) {
            $this->connect();
        }

        return $this->channel;
    }

    /**
     * @return AMQPStreamConnection
     */
    public function getConnection(): AMQPStreamConnection
    {
        if (!$this->connection) {
            $this->connect();
        }

        return $this->connection;
    }

    /**
     * @return void
     */
    public function disconnect(): void
    {
        try {
            if ($this->channel && method_exists($this->channel, 'is_open')) {
                if ($this->channel->is_open()) {
                    $this->channel->close();
                }
            }
        } catch (\Exception $e) {
            // Continue to close connection
        }

        try {
            if ($this->connection && method_exists($this->connection, 'isConnected')) {
                if ($this->connection->isConnected()) {
                    $this->connection->close();
                }
            }
        } catch (\Exception $e) {
            // Connection cleanup is best effort
        }

        $this->channel = null;
        $this->connection = null;
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connection !== null 
            && $this->channel !== null
            && method_exists($this->connection, 'isConnected')
            && $this->connection->isConnected();
    }
}


