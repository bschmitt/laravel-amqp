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
     * Callback function for ack confirmations
     * @var callable|null
     */
    private $ackHandler = null;

    /**
     * Callback function for nack confirmations
     * @var callable|null
     */
    private $nackHandler = null;

    /**
     * Callback function for return messages
     * @var callable|null
     */
    private $returnHandler = null;

    /**
     * Whether publisher confirms are enabled
     * @var bool
     */
    private $confirmsEnabled = false;

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

        // Enable publisher confirms if configured
        if ($this->getProperty('publisher_confirms', false)) {
            $this->enablePublisherConfirms();
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

        // Enable confirms if mandatory flag is set (backward compatibility)
        if ($mandatory && !$this->confirmsEnabled) {
            $this->enablePublisherConfirms();
        }

        // Setup handlers if confirms are enabled
        if ($this->confirmsEnabled) {
            if ($mandatory) {
                $channel->set_return_listener([$this, 'return']);
            }
        }

        $timeout = max(1, (int) $this->getProperty('publish_timeout', 30));
        $exchange = $this->getProperty('exchange');

        $channel->basic_publish($message, $exchange, $routing, $mandatory);

        // Wait for confirms if enabled and configured to wait
        if ($this->confirmsEnabled && $this->getProperty('wait_for_confirms', true)) {
            $channel->wait_for_pending_acks_returns($timeout);
        }

        return $this->publishResult;
    }

    /**
     * Enable publisher confirms on the channel
     *
     * @return void
     */
    public function enablePublisherConfirms(): void
    {
        if ($this->confirmsEnabled) {
            return; // Already enabled
        }

        $channel = $this->getChannel();
        $channel->confirm_select();
        $this->confirmsEnabled = true;

        // Set up handlers
        $channel->set_ack_handler([$this, 'handleAck']);
        $channel->set_nack_handler([$this, 'handleNack']);
    }

    /**
     * Disable publisher confirms on the channel
     *
     * @return void
     */
    public function disablePublisherConfirms(): void
    {
        if (!$this->confirmsEnabled) {
            return; // Already disabled
        }

        $channel = $this->getChannel();
        // Note: php-amqplib doesn't have a direct way to disable confirms
        // We just mark it as disabled
        $this->confirmsEnabled = false;
    }

    /**
     * Register a callback for ack confirmations
     *
     * @param callable $callback Function to call when message is acked
     * @return void
     */
    public function setAckHandler(callable $callback): void
    {
        $this->ackHandler = $callback;
    }

    /**
     * Register a callback for nack confirmations
     *
     * @param callable $callback Function to call when message is nacked
     * @return void
     */
    public function setNackHandler(callable $callback): void
    {
        $this->nackHandler = $callback;
    }

    /**
     * Register a callback for return messages
     *
     * @param callable $callback Function to call when message is returned
     * @return void
     */
    public function setReturnHandler(callable $callback): void
    {
        $this->returnHandler = $callback;
    }

    /**
     * Wait for pending publisher confirms
     *
     * @param int|null $timeout Timeout in seconds (null uses default from config)
     * @return bool True if all confirms received, false on timeout or error
     */
    public function waitForConfirms(?int $timeout = null): bool
    {
        if (!$this->confirmsEnabled) {
            throw new \RuntimeException('Publisher confirms are not enabled. Call enablePublisherConfirms() first.');
        }

        $channel = $this->getChannel();
        $timeout = $timeout ?? max(1, (int) $this->getProperty('publish_timeout', 30));

        try {
            $result = $channel->wait_for_pending_acks($timeout);
            return $result === true; // Ensure boolean return
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Wait for pending publisher confirms and returns
     *
     * @param int|null $timeout Timeout in seconds (null uses default from config)
     * @return bool True if all confirms received, false on timeout or error
     */
    public function waitForConfirmsAndReturns(?int $timeout = null): bool
    {
        if (!$this->confirmsEnabled) {
            throw new \RuntimeException('Publisher confirms are not enabled. Call enablePublisherConfirms() first.');
        }

        $channel = $this->getChannel();
        $timeout = $timeout ?? max(1, (int) $this->getProperty('publish_timeout', 30));

        try {
            $result = $channel->wait_for_pending_acks_returns($timeout);
            // wait_for_pending_acks_returns can return true, false, or null
            // Return true only if explicitly true, otherwise false
            return $result === true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Handle ack confirmation
     *
     * @param \PhpAmqpLib\Message\AMQPMessage $msg
     * @return void
     */
    public function handleAck($msg): void
    {
        if ($this->ackHandler !== null) {
            call_user_func($this->ackHandler, $msg);
        }
    }

    /**
     * Handle nack confirmation
     *
     * @param \PhpAmqpLib\Message\AMQPMessage $msg
     * @return void
     */
    public function handleNack($msg): void
    {
        $this->publishResult = false;

        if ($this->nackHandler !== null) {
            call_user_func($this->nackHandler, $msg);
        } else {
            // Fallback to old nack method for backward compatibility
            $this->nack($msg);
        }
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
     * Handle return message
     *
     * @param \PhpAmqpLib\Message\AMQPMessage $msg
     * @return void
     */
    public function handleReturn($msg): void
    {
        $this->publishResult = false;

        if ($this->returnHandler !== null) {
            call_user_func($this->returnHandler, $msg);
        } else {
            // Fallback to old return method for backward compatibility
            $this->return($msg);
        }
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
     * Check if publisher confirms are enabled
     *
     * @return bool
     */
    public function isConfirmsEnabled(): bool
    {
        return $this->confirmsEnabled;
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

    /**
     * Get connection manager (for resource cleanup)
     *
     * @return ConnectionManagerInterface|null
     */
    public function getConnectionManager(): ?ConnectionManagerInterface
    {
        return $this->connectionManager;
    }
}
