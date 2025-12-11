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
        // Merge properties with default config if available
        $mergedProperties = $properties;
        if ($this->defaultConfig !== null) {
            $defaultProperties = $this->defaultConfig->getProperties();
            $mergedProperties = array_merge($defaultProperties, $properties);
        }
        
        // If merged properties contain full config (host, port, etc.), create Repository from them
        // This avoids App facade issues in test environments
        if (isset($mergedProperties['host'])) {
            // Create a Repository from properties to avoid App facade issues
            $config = new \Illuminate\Config\Repository([
                'amqp' => [
                    'use' => 'production',
                    'properties' => [
                        'production' => $mergedProperties,
                    ],
                ],
            ]);
            $publisher = new Publisher($config);
        } elseif ($this->defaultConfig !== null) {
            // If defaultConfig is a ConfigurationProvider, get its Repository
            if ($this->defaultConfig instanceof \Bschmitt\Amqp\Support\ConfigurationProvider) {
                // Get the original Repository from the ConfigurationProvider
                $originalConfig = $this->defaultConfig->getConfigRepository();
                // Create new config with merged properties
                $config = new \Illuminate\Config\Repository([
                    'amqp' => [
                        'use' => 'production',
                        'properties' => [
                            'production' => $mergedProperties,
                        ],
                    ],
                ]);
                $publisher = new Publisher($config);
            } else {
                $publisher = new Publisher($this->defaultConfig);
                // Merge properties if provided
                if (!empty($properties)) {
                    $publisher->mergeProperties($properties);
                }
            }
        } else {
            // Fallback: create with Laravel config (for backward compatibility)
            try {
                $config = \Illuminate\Support\Facades\App::make('config');
            } catch (\Exception $e) {
                // If App facade not available, create minimal config from properties
                $config = new \Illuminate\Config\Repository([
                    'amqp' => [
                        'use' => 'production',
                        'properties' => [
                            'production' => $mergedProperties ?: [
                                'host' => 'localhost',
                                'port' => 5672,
                                'username' => 'guest',
                                'password' => 'guest',
                                'vhost' => '/',
                            ],
                        ],
                    ],
                ]);
            }
            $publisher = new Publisher($config);
            // Merge properties if provided
            if (!empty($properties)) {
                $publisher->mergeProperties($properties);
            }
        }

        $publisher->setup();
        return $publisher;
    }
}
