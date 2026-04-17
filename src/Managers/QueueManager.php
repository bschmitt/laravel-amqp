<?php

namespace Bschmitt\Amqp\Managers;

use Bschmitt\Amqp\Contracts\ConfigurationProviderInterface;
use Bschmitt\Amqp\Contracts\ConnectionManagerInterface;
use PhpAmqpLib\Wire\AMQPTable;

class QueueManager
{
    /**
     * @var ConfigurationProviderInterface
     */
    protected $config;

    /**
     * @var ConnectionManagerInterface
     */
    protected $connectionManager;

    /**
     * @var array|null
     */
    protected $queueInfo;

    /**
     * @param ConfigurationProviderInterface $config
     * @param ConnectionManagerInterface $connectionManager
     */
    public function __construct(
        ConfigurationProviderInterface $config,
        ConnectionManagerInterface $connectionManager
    ) {
        $this->config = $config;
        $this->connectionManager = $connectionManager;
    }

    /**
     * Declare and bind queue if needed
     *
     * @return void
     */
    public function declareAndBind(): void
    {
        $queue = $this->config->getProperty('queue');
        $forceDeclare = $this->config->getProperty('queue_force_declare', false);

        if (empty($queue) && !$forceDeclare) {
            return;
        }

        $this->declareQueue($queue);
        $this->bindQueue($queue);
    }

    /**
     * Declare a queue
     *
     * @param string|null $queue
     * @return void
     */
    protected function declareQueue(?string $queue): void
    {
        $queueProperties = $this->normalizeQueueProperties(
            $this->config->getProperty('queue_properties')
        );

        $this->queueInfo = $this->connectionManager->getChannel()->queue_declare(
            $queue ?: '',
            $this->config->getProperty('queue_passive', false),
            $this->config->getProperty('queue_durable', true),
            $this->config->getProperty('queue_exclusive', false),
            $this->config->getProperty('queue_auto_delete', false),
            $this->config->getProperty('queue_nowait', false),
            $queueProperties
        );
    }

    /**
     * Bind queue to exchange
     *
     * @param string|null $queue
     * @return void
     */
    protected function bindQueue(?string $queue): void
    {
        $routingKeys = (array) $this->config->getProperty('routing', []);
        $exchange = $this->config->getProperty('exchange');
        $actualQueueName = $queue ?: ($this->queueInfo[0] ?? '');

        foreach ($routingKeys as $routingKey) {
            if (!empty($routingKey)) {
                $this->connectionManager->getChannel()->queue_bind(
                    $actualQueueName,
                    $exchange,
                    $routingKey
                );
            }
        }
    }

    /**
     * Normalize queue properties to AMQPTable or null
     *
     * @param mixed $queueProperties
     * @return AMQPTable|null
     */
    protected function normalizeQueueProperties($queueProperties)
    {
        if ($queueProperties instanceof AMQPTable) {
            return $queueProperties;
        }

        if (is_array($queueProperties) && !empty($queueProperties)) {
            $filtered = [];
            foreach ($queueProperties as $key => $value) {
                if ($key !== 'x-ha-policy' && $value !== null) {
                    $filtered[$key] = $value;
                }
            }

            return !empty($filtered) ? new AMQPTable($filtered) : null;
        }

        return null;
    }

    /**
     * Get queue message count
     *
     * @return int
     */
    public function getMessageCount(): int
    {
        if (is_array($this->queueInfo) && isset($this->queueInfo[1])) {
            return $this->queueInfo[1];
        }

        return 0;
    }

    /**
     * Get queue info
     *
     * @return array|null
     */
    public function getQueueInfo(): ?array
    {
        return $this->queueInfo;
    }
}



