<?php

namespace Bschmitt\Amqp\Managers;

use Bschmitt\Amqp\Contracts\ConfigurationProviderInterface;
use Bschmitt\Amqp\Contracts\ConnectionManagerInterface;
use PhpAmqpLib\Wire\AMQPTable;

class ExchangeManager
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
     * Declare an exchange
     *
     * @return void
     * @throws \Bschmitt\Amqp\Exception\Configuration
     */
    public function declareExchange(): void
    {
        $exchange = $this->config->getProperty('exchange');

        if (empty($exchange)) {
            throw new \Bschmitt\Amqp\Exception\Configuration('Exchange is not defined in configuration.');
        }

        $exchangeProperties = $this->normalizeProperties(
            $this->config->getProperty('exchange_properties')
        );

        $this->connectionManager->getChannel()->exchange_declare(
            $exchange,
            $this->config->getProperty('exchange_type', 'topic'),
            $this->config->getProperty('exchange_passive', false),
            $this->config->getProperty('exchange_durable', true),
            $this->config->getProperty('exchange_auto_delete', false),
            $this->config->getProperty('exchange_internal', false),
            $this->config->getProperty('exchange_nowait', false),
            $exchangeProperties
        );
    }

    /**
     * Normalize properties to AMQPTable or null
     *
     * @param mixed $properties
     * @return AMQPTable|null
     */
    protected function normalizeProperties($properties)
    {
        if ($properties instanceof AMQPTable) {
            return $properties;
        }

        if (is_array($properties) && !empty($properties)) {
            return new AMQPTable($properties);
        }

        return null;
    }
}


