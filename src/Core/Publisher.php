<?php

namespace Bschmitt\Amqp\Core;

use Bschmitt\Amqp\Contracts\PublisherInterface;
use Bschmitt\Amqp\Contracts\ConfigurationProviderInterface;
use Bschmitt\Amqp\Contracts\ConnectionManagerInterface;
use Bschmitt\Amqp\Managers\ExchangeManager;
use Bschmitt\Amqp\Managers\QueueManager;
use Bschmitt\Amqp\Models\Message;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Publisher extends Request implements PublisherInterface
{
    /**
     * @var ConnectionManagerInterface
     */
    protected $connectionManager;

    /**
     * @var ExchangeManager
     */
    protected $exchangeManager;

    /**
     * @var QueueManager
     */
    protected $queueManager;

    /**
     * The result of the call to basic_publish
     * Assumed to be successful unless overridden with
     * a basic.return or basic.nack. Only valid when in
     * confirm_select mode
     */
    private $publishResult = null;

    /**
     * @param ConfigurationProviderInterface|null $config
     * @param ConnectionManagerInterface|null $connectionManager
     * @param ExchangeManager|null $exchangeManager
     * @param QueueManager|null $queueManager
     */
    public function __construct(
        $config = null,
        ConnectionManagerInterface $connectionManager = null,
        ExchangeManager $exchangeManager = null,
        QueueManager $queueManager = null
    ) {
        if ($config === null) {
            $config = \Illuminate\Support\Facades\App::make('config');
        }

        if ($config === null || !($config instanceof \Illuminate\Contracts\Config\Repository)) {
            $config = \Illuminate\Support\Facades\App::make('config');
        }

        parent::__construct($config);

        // Only create managers if explicitly provided or if we're using the new structure
        // This allows backward compatibility with tests that mock the old structure
        if ($connectionManager !== null || $exchangeManager !== null || $queueManager !== null) {
            if ($connectionManager === null) {
                $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($this);
            }

            if ($exchangeManager === null) {
                $exchangeManager = new ExchangeManager($this, $connectionManager);
            }

            if ($queueManager === null) {
                $queueManager = new QueueManager($this, $connectionManager);
            }

            $this->connectionManager = $connectionManager;
            $this->exchangeManager = $exchangeManager;
            $this->queueManager = $queueManager;
        }
    }

    /**
     * @return void
     */
    public function setup(): void
    {
        if ($this->connectionManager !== null && $this->exchangeManager !== null && $this->queueManager !== null) {
            $this->connectionManager->connect();
            $this->exchangeManager->declareExchange();
            $this->queueManager->declareAndBind();
        } else {
            // Backward compatibility: use old Request::setup() method
            parent::setup();
        }
    }

    /**
     * @param string         $routing
     * @param string|Message $message
     * @param bool           $mandatory
     *
     * @return bool|null
     */
    public function publish(string $routing, $message, bool $mandatory = false): ?bool
    {
        $this->publishResult = true;

        $channel = $this->getChannel();

        if ($mandatory) {
            $channel->confirm_select();
            $channel->set_nack_handler([$this, 'nack']);
            $channel->set_return_listener([$this, 'return']);
        }

        $timeout = max(1, (int) $this->getProperty('publish_timeout', 30));
        $exchange = $this->getProperty('exchange');

        $channel->basic_publish($message, $exchange, $routing, $mandatory);

        if ($mandatory) {
            $channel->wait_for_pending_acks_returns($timeout);
        }

        return $this->publishResult;
    }

    /**
     * @param \PhpAmqpLib\Message\AMQPMessage $msg
     * @return void
     */
    public function nack($msg): void
    {
        $this->publishResult = false;
    }

    /**
     * @param \PhpAmqpLib\Message\AMQPMessage $msg
     * @return void
     */
    public function return($msg): void
    {
        $this->publishResult = false;
    }

    /**
     * @param string         $routing
     * @param Message|string $message
     * @return void
     */
    public function batchBasicPublish(string $routing, $message): void
    {
        $this->getChannel()->batch_basic_publish(
            $message,
            $this->getProperty('exchange'),
            $routing
        );
    }

    /**
     * @return void
     */
    public function batchPublish(): void
    {
        $this->getChannel()->publish_batch();
    }

    /**
     * @return \PhpAmqpLib\Channel\AMQPChannel
     */
    public function getChannel(): \PhpAmqpLib\Channel\AMQPChannel
    {
        if ($this->connectionManager !== null) {
            return $this->connectionManager->getChannel();
        }
        return parent::getChannel();
    }

    /**
     * @return \PhpAmqpLib\Connection\AMQPStreamConnection
     */
    public function getConnection(): \PhpAmqpLib\Connection\AMQPStreamConnection
    {
        if ($this->connectionManager !== null) {
            return $this->connectionManager->getConnection();
        }
        return parent::getConnection();
    }
}
