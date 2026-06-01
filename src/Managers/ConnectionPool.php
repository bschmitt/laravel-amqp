<?php

namespace Bschmitt\Amqp\Managers;

use Bschmitt\Amqp\Contracts\ConnectionManagerInterface;
use Bschmitt\Amqp\Contracts\ConfigurationProviderInterface;
use Bschmitt\Amqp\Support\ConfigurationProvider;

/**
 * Cache AMQP connections by logical key for workers that publish frequently.
 *
 * Mark a key {@see connection()} as persistent to keep its channel open across
 * requests; call {@see disconnectAll()} on shutdown unless `force` is false.
 */
class ConnectionPool
{
    /** @var self|null */
    protected static $instance;

    /** @var array<string, ConnectionManagerInterface> */
    protected $connections = [];

    /** @var array<string, bool> */
    protected $persistentKeys = [];

    /** @var callable|null */
    protected $factory;

    /**
     * @return self
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reset the global singleton (primarily for tests).
     *
     * @return void
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->disconnectAll(true);
        }
        self::$instance = null;
    }

    /**
     * @param callable $factory function (string $key, array $config): ConnectionManagerInterface
     * @return $this
     */
    public function setFactory(callable $factory): self
    {
        $this->factory = $factory;

        return $this;
    }

    /**
     * @param string $key
     * @param array<string, mixed> $config Connection properties or empty for default.
     * @param bool $persistent When true, {@see disconnectAll()} skips this key unless forced.
     * @return ConnectionManagerInterface
     */
    public function connection(string $key, array $config = [], bool $persistent = false): ConnectionManagerInterface
    {
        if (isset($this->connections[$key])) {
            $manager = $this->connections[$key];
            if ($manager instanceof ResilientConnectionManager) {
                $manager->ensureConnected();
            } elseif (!$manager->isConnected()) {
                $manager->connect();
            }

            return $manager;
        }

        $manager = $this->createConnection($key, $config);
        if (!$manager->isConnected()) {
            $manager->connect();
        }

        $this->connections[$key] = $manager;
        if ($persistent) {
            $this->persistentKeys[$key] = true;
        }

        return $manager;
    }

    /**
     * @param string|null $key When null, disconnect every non-persistent connection.
     * @param bool        $force Disconnect persistent keys as well.
     * @return void
     */
    public function disconnect(?string $key = null, bool $force = false): void
    {
        if ($key === null) {
            $this->disconnectAll($force);

            return;
        }

        if (!isset($this->connections[$key])) {
            return;
        }

        if (!$force && !empty($this->persistentKeys[$key])) {
            return;
        }

        $this->connections[$key]->disconnect();
        unset($this->connections[$key], $this->persistentKeys[$key]);
    }

    /**
     * @param bool $force
     * @return void
     */
    public function disconnectAll(bool $force = false): void
    {
        foreach (array_keys($this->connections) as $key) {
            $this->disconnect($key, $force);
        }
    }

    /**
     * @param string $key
     * @param array<string, mixed> $config
     * @return ConnectionManagerInterface
     */
    protected function createConnection(string $key, array $config): ConnectionManagerInterface
    {
        if ($this->factory !== null) {
            return call_user_func($this->factory, $key, $config);
        }

        $provider = $this->buildConfigurationProvider($key, $config);
        $resilient = !empty($config['resilient']);
        $inner = new ConnectionManager($provider);

        if (!$resilient) {
            return $inner;
        }

        $options = (array) ($config['resilient_options'] ?? []);
        if (!isset($options['heartbeat'])) {
            $connectOptions = (array) $provider->getProperty('connect_options', []);
            if (isset($connectOptions['heartbeat'])) {
                $options['heartbeat'] = (int) $connectOptions['heartbeat'];
            }
        }

        return new ResilientConnectionManager($inner, $options);
    }

    /**
     * @param string $key
     * @param array<string, mixed> $config
     * @return ConfigurationProviderInterface
     */
    protected function buildConfigurationProvider(string $key, array $config): ConfigurationProviderInterface
    {
        try {
            $base = \Illuminate\Support\Facades\App::make(ConfigurationProviderInterface::class);
            if ($base instanceof ConfigurationProvider) {
                $clone = clone $base;
                $clone->mergeProperties($config);

                return $clone;
            }
        } catch (\Exception $e) {
            // Fall through to file-based defaults.
        }

        $defaultConfig = include __DIR__ . '/../../config/amqp.php';
        $connectionName = $config['use'] ?? $key;
        if ($connectionName === $key && isset($defaultConfig['properties'][$key])) {
            $merged = array_merge($defaultConfig['properties'][$key], $config);
        } else {
            $defaultName = $defaultConfig['use'] ?? 'production';
            $merged = array_merge(
                $defaultConfig['properties'][$defaultName] ?? [],
                $config
            );
            $connectionName = $config['use'] ?? $defaultName;
        }

        $configArray = [
            'amqp' => [
                'use' => $connectionName,
                'properties' => [
                    $connectionName => $merged,
                ],
            ],
        ];

        $repository = new \Illuminate\Config\Repository($configArray);

        return new ConfigurationProvider($repository);
    }
}
