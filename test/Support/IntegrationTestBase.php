<?php

namespace Bschmitt\Amqp\Test\Support;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Factories\ConsumerFactory;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Factories\PublisherFactory;
use Bschmitt\Amqp\Managers\BatchManager;
use Bschmitt\Amqp\Support\ConfigurationProvider;
use Illuminate\Config\Repository;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests using real RabbitMQ connections
 * No mocks are used - all tests connect to a real RabbitMQ instance
 */
class IntegrationTestBase extends TestCase
{
    protected $configRepository;
    protected $defaultConfig;
    protected $testQueueName;
    protected $testExchange;
    protected $testRoutingKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Check if RabbitMQ is available
        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available. Please ensure RabbitMQ is running and credentials are set in .env');
        }

        // Use fixed names for consistent testing and Web UI visibility
        $this->testQueueName = 'test-queue-integration';
        $this->testExchange = 'test-exchange-integration';
        $this->testRoutingKey = 'test.routing.key';

        // Load real configuration from .env
        $amqpConfig = include dirname(__FILE__) . '/../../config/amqp.php';
        $defaultProperties = $amqpConfig['properties'][$amqpConfig['use']];

        // Load .env file if it exists
        $this->loadEnvFile();

        // Override with environment variables if set
        $config = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => array_merge($defaultProperties, [
                        'host' => $this->getEnv('AMQP_HOST', $defaultProperties['host'] ?? 'localhost'),
                        'port' => (int) $this->getEnv('AMQP_PORT', $defaultProperties['port'] ?? 5672),
                        'username' => $this->getEnv('AMQP_USER', $defaultProperties['username'] ?? 'guest'),
                        'password' => $this->getEnv('AMQP_PASSWORD', $defaultProperties['password'] ?? 'guest'),
                        'vhost' => $this->getEnv('AMQP_VHOST', $defaultProperties['vhost'] ?? '/'),
                        'exchange' => $this->testExchange,
                        'queue' => $this->testQueueName,
                        'queue_force_declare' => true,
                        'queue_passive' => false,
                        'queue_durable' => false,
                        'queue_auto_delete' => true,
                        'queue_properties' => [],
                        'routing' => $this->testRoutingKey,
                        'persistent' => true, // Keep consumer running even if queue is empty initially
                        'timeout' => 5, // 5 second timeout for waiting
                        'consumer_tag' => 'test-consumer',
                        'consumer_no_local' => false,
                        'consumer_no_ack' => false,
                        'consumer_exclusive' => false,
                        'consumer_nowait' => false,
                    ])
                ]
            ]
        ];

        $this->configRepository = new Repository($config);
        $this->defaultConfig = $config['amqp']['properties']['test'];

        $this->resetIntegrationTestTopology();
    }

    protected function tearDown(): void
    {
        // Note: Queue persists with messages, so we don't delete it here
        // This allows messages to accumulate for Web UI inspection
        // To delete the queue manually, use RabbitMQ Web UI or management API
        parent::tearDown();
    }
    
    /**
     * Remove shared integration queue/exchange so declare args stay consistent across runs.
     */
    protected function resetIntegrationTestTopology(): void
    {
        $this->deleteBrokerQueue($this->testQueueName);
        $this->deleteBrokerExchange($this->testExchange);
    }

    /**
     * Delete the test queue (useful when a test needs custom queue_properties).
     */
    protected function deleteTestQueue(): void
    {
        $this->deleteBrokerQueue($this->testQueueName);
    }

    protected function deleteBrokerQueue(string $queueName): void
    {
        try {
            [$connection, $channel] = $this->openBrokerChannel();
            $channel->queue_delete($queueName);
            $channel->close();
            $connection->close();
        } catch (\Throwable $e) {
            // Queue may not exist
        }
    }

    protected function deleteBrokerExchange(string $exchangeName): void
    {
        try {
            [$connection, $channel] = $this->openBrokerChannel();
            $channel->exchange_delete($exchangeName);
            $channel->close();
            $connection->close();
        } catch (\Throwable $e) {
            // Exchange may not exist
        }
    }

    /**
     * @return array{0: AMQPStreamConnection, 1: \PhpAmqpLib\Channel\AMQPChannel}
     */
    private function openBrokerChannel(): array
    {
        $props = $this->defaultConfig;

        $connection = new AMQPStreamConnection(
            $props['host'],
            (int) $props['port'],
            $props['username'],
            $props['password'],
            $props['vhost']
        );

        return [$connection, $connection->channel()];
    }

    /**
     * Check if RabbitMQ is available
     */
    protected function isRabbitMQAvailable(): bool
    {
        $host = $this->getEnv('AMQP_HOST', 'localhost');
        $port = (int) $this->getEnv('AMQP_PORT', 5672);

        $connection = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($connection) {
            fclose($connection);
            return true;
        }
        return false;
    }

    /**
     * Load .env file from project root
     */
    protected function loadEnvFile(): void
    {
        $envFile = dirname(__FILE__) . '/../../../../../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if (!getenv($key)) {
                        putenv("$key=$value");
                        $_ENV[$key] = $value;
                    }
                }
            }
        }
    }

    /**
     * Get environment variable
     */
    protected function getEnv(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }

    /**
     * Create a test message
     */
    protected function createMessage(string $body, array $properties = []): \Bschmitt\Amqp\Models\Message
    {
        $defaultProperties = [
            'content_type' => 'text/plain',
            'delivery_mode' => 2
        ];

        return new \Bschmitt\Amqp\Models\Message($body, array_merge($defaultProperties, $properties));
    }

    /**
     * Build an Amqp instance with the same wiring as the service provider (no Laravel container).
     */
    protected function makeAmqp(array $propertyOverrides = []): Amqp
    {
        $config = $this->configRepository->get('amqp');
        if (!empty($propertyOverrides)) {
            $config['properties']['test'] = array_merge($config['properties']['test'], $propertyOverrides);
        }

        $configProvider = new ConfigurationProvider(new Repository(['amqp' => $config]));

        return new Amqp(
            new PublisherFactory($configProvider),
            new ConsumerFactory($configProvider),
            new MessageFactory(),
            new BatchManager()
        );
    }

    /**
     * Publish one or more messages to the shared integration test queue.
     */
    protected function seedTestQueue(int $count = 1, string $prefix = 'seed-message'): void
    {
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        for ($i = 1; $i <= $count; $i++) {
            $publisher->publish(
                $this->testRoutingKey,
                $this->createMessage("{$prefix}-{$i}")
            );
        }

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }
}

