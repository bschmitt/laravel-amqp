<?php

namespace Bschmitt\Amqp\Test;

use Illuminate\Config\Repository;
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
        $amqpConfig = include dirname(__FILE__) . '/../config/amqp.php';
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
                        'queue_force_declare' => false, // Don't force declare - reuse existing queue
                        'queue_passive' => false, // Allow declaration if queue doesn't exist
                        'queue_durable' => false, // Non-durable
                        'queue_auto_delete' => true, // Auto-delete (queue persists while it has messages/consumers)
                        // Note: If queue already exists with x-max-length, it will keep that property
                        // To allow messages to accumulate, delete the queue first via RabbitMQ Web UI
                        'queue_properties' => ['x-max-length' => 1], // Match existing queue properties
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
    }

    protected function tearDown(): void
    {
        // Note: Queue persists with messages, so we don't delete it here
        // This allows messages to accumulate for Web UI inspection
        // To delete the queue manually, use RabbitMQ Web UI or management API
        parent::tearDown();
    }
    
    /**
     * Delete the test queue (useful for cleanup between test runs)
     * Call this manually if you need to reset the queue
     */
    protected function deleteTestQueue(): void
    {
        try {
            $consumer = new \Bschmitt\Amqp\Core\Consumer($this->configRepository);
            $consumer->setup();
            $channel = $consumer->getChannel();
            $channel->queue_delete($this->testQueueName);
            \Bschmitt\Amqp\Core\Request::shutdown($channel, $consumer->getConnection());
            echo "[CLEANUP] Deleted queue: {$this->testQueueName}\n";
        } catch (\Exception $e) {
            echo "[CLEANUP] Could not delete queue: " . $e->getMessage() . "\n";
        }
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
        $envFile = dirname(__FILE__) . '/../../../../.env';
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
}

