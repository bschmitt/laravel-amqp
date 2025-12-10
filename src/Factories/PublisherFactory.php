<?php

namespace Bschmitt\Amqp\Factories;

use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Contracts\PublisherInterface;
use Bschmitt\Amqp\Contracts\ConfigurationProviderInterface;
use Bschmitt\Amqp\Core\Publisher;
use Illuminate\Contracts\Config\Repository;

/**
 * Factory for creating Publisher instances
 */
class PublisherFactory implements PublisherFactoryInterface
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
     * Create a new publisher instance with optional properties
     *
     * @param array $properties
     * @return PublisherInterface
     */
    public function create(array $properties = []): PublisherInterface
    {
        // Create publisher with default config
        if ($this->defaultConfig !== null) {
            $publisher = new Publisher($this->defaultConfig);
        } else {
            // Fallback: create with Laravel config (for backward compatibility)
            $config = \Illuminate\Support\Facades\App::make('config');
            $publisher = new Publisher($config);
        }

        // Merge properties if provided
        if (!empty($properties)) {
            $publisher->mergeProperties($properties);
        }

        $publisher->setup();
        return $publisher;
    }
}

