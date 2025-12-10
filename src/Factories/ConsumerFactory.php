<?php

namespace Bschmitt\Amqp\Factories;

use Bschmitt\Amqp\Contracts\ConsumerFactoryInterface;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\ConfigurationProviderInterface;
use Bschmitt\Amqp\Core\Consumer;
use Illuminate\Contracts\Config\Repository;

/**
 * Factory for creating Consumer instances
 */
class ConsumerFactory implements ConsumerFactoryInterface
{
    /**
     * @var ConfigurationProviderInterface
     */
    protected $defaultConfig;

    /**
     * @param ConfigurationProviderInterface|null $defaultConfig
     */
    public function __construct(ConfigurationProviderInterface $defaultConfig = null)
    {
        $this->defaultConfig = $defaultConfig;
    }

    /**
     * Create a new consumer instance with optional properties
     *
     * @param array $properties
     * @return ConsumerInterface
     */
    public function create(array $properties = []): ConsumerInterface
    {
        // Create consumer with default config
        if ($this->defaultConfig !== null) {
            $consumer = new Consumer($this->defaultConfig);
        } else {
            // Fallback: create with Laravel config (for backward compatibility)
            $config = \Illuminate\Support\Facades\App::make('config');
            $consumer = new Consumer($config);
        }

        // Merge properties if provided
        if (!empty($properties)) {
            $consumer->mergeProperties($properties);
        }

        $consumer->setup();
        return $consumer;
    }
}

