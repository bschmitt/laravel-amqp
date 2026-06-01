<?php

namespace Bschmitt\Amqp\Queue;

use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Factories\MessageFactory;
use Illuminate\Queue\Connectors\ConnectorInterface;

class AmqpConnector implements ConnectorInterface
{
    /**
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * @param \Illuminate\Contracts\Container\Container $container
     */
    public function __construct($container)
    {
        $this->container = $container;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(array $config)
    {
        return new AmqpQueue(
            $this->container,
            $config,
            $this->container->make(PublisherFactoryInterface::class),
            $this->container->make(MessageFactory::class)
        );
    }
}
