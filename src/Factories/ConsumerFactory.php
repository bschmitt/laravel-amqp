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
        // Check if properties contain full config (host, port, etc.) FIRST
        // This avoids App facade issues in test environments
        if (!empty($properties) && isset($properties['host'])) {
            // Properties already have full config, use them directly
            $config = new \Illuminate\Config\Repository([
                'amqp' => [
                    'use' => 'production',
                    'properties' => [
                        'production' => $properties,
                    ],
                ],
            ]);
            $consumer = new Consumer($config);
        } elseif ($this->defaultConfig !== null) {
            // Merge properties with default config if available
            $defaultProperties = $this->defaultConfig->getProperties();
            $mergedProperties = array_merge($defaultProperties, $properties);
            
            // If merged properties now have host, use them
            if (isset($mergedProperties['host'])) {
                $config = new \Illuminate\Config\Repository([
                    'amqp' => [
                        'use' => 'production',
                        'properties' => [
                            'production' => $mergedProperties,
                        ],
                    ],
                ]);
                $consumer = new Consumer($config);
            } else {
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
                    $consumer = new Consumer($config);
                } else {
                    $consumer = new Consumer($this->defaultConfig);
                    // Merge properties if provided
                    if (!empty($properties)) {
                        $consumer->mergeProperties($properties);
                    }
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
                            'production' => $properties ?: [
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
            $consumer = new Consumer($config);
            // Merge properties if provided
            if (!empty($properties)) {
                $consumer->mergeProperties($properties);
            }
        }

        $consumer->setup();
        return $consumer;
    }
}

