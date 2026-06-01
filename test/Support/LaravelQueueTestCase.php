<?php

namespace Bschmitt\Amqp\Test\Support;

use Bschmitt\Amqp\Providers\AmqpServiceProvider;
use Bschmitt\Amqp\Queue\AmqpConnector;
use Bschmitt\Amqp\Queue\AmqpQueue;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for native Laravel queue driver integration tests.
 *
 * Each test runs against a real RabbitMQ broker (skipped when unreachable) and
 * registers any queues it touches for automatic deletion in tearDown so the
 * suite is safe to run repeatedly without leaving orphaned topology.
 */
abstract class LaravelQueueTestCase extends TestCase
{
    /** @var Container */
    protected $container;

    /** @var array */
    protected $amqpConfig;

    /** @var string[] */
    private $queuesToCleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadEnvFile();

        if (!$this->isRabbitMqReachable()) {
            $this->markTestSkipped(sprintf(
                'RabbitMQ not reachable on %s:%d - set AMQP_HOST/AMQP_PORT or start the broker.',
                $this->host(),
                $this->port()
            ));
        }

        $this->amqpConfig = $this->buildAmqpConfig();
        $this->container = new Container();
        $this->container->instance('config', new Repository(['amqp' => $this->amqpConfig]));

        (new AmqpServiceProvider($this->container))->register();
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->queuesToCleanup) as $queue) {
            $this->safeDeleteQueue($queue);
        }
        $this->queuesToCleanup = [];

        parent::tearDown();
    }

    /**
     * Build an AmqpQueue wired through the connector, exactly as Laravel would.
     */
    protected function makeQueue(string $queue, array $extra = []): AmqpQueue
    {
        $queueConfig = array_merge([
            'driver' => 'amqp',
            'connection' => $this->amqpConfig['use'],
            'queue' => $queue,
            'retry_after' => 90,
        ], $extra);

        /** @var AmqpQueue $instance */
        $instance = (new AmqpConnector($this->container))->connect($queueConfig);
        $instance->setConnectionName('amqp');

        return $instance;
    }

    /**
     * Reserve a unique queue name and schedule it for cleanup in tearDown.
     */
    protected function uniqueQueueName(string $prefix = 'laravel-queue-test'): string
    {
        $name = sprintf('%s-%s', $prefix, bin2hex(random_bytes(4)));
        $this->trackQueue($name);

        return $name;
    }

    /**
     * Mark a queue (e.g. an auto-created delay queue) for cleanup in tearDown.
     */
    protected function trackQueue(string $queue): void
    {
        $this->queuesToCleanup[] = $queue;
    }

    /**
     * Build a Laravel-shaped raw job payload suitable for pushRaw().
     */
    protected function laravelJobPayload(array $overrides = []): string
    {
        $payload = array_replace([
            'id' => $this->uuid(),
            'uuid' => $this->uuid(),
            'displayName' => 'stdClass',
            'job' => \Illuminate\Queue\CallQueuedHandler::class.'@call',
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'data' => [
                'commandName' => 'stdClass',
                'command' => 'O:8:"stdClass":0:{}',
            ],
        ], $overrides);

        return json_encode($payload);
    }

    protected function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    protected function host(): string
    {
        return (string) $this->envValue('AMQP_HOST', 'localhost');
    }

    protected function port(): int
    {
        return (int) $this->envValue('AMQP_PORT', 5672);
    }

    private function buildAmqpConfig(): array
    {
        $config = include dirname(__DIR__, 2).'/config/amqp.php';
        $key = $config['use'];
        $properties = $config['properties'][$key];

        // Override broker credentials with whatever the test environment provides.
        $properties['host'] = $this->host();
        $properties['port'] = $this->port();
        $properties['username'] = (string) $this->envValue('AMQP_USER', 'guest');
        $properties['password'] = (string) $this->envValue('AMQP_PASSWORD', 'guest');
        $properties['vhost'] = (string) $this->envValue('AMQP_VHOST', '/');

        // Queue test queues must be safe to create/delete and free of
        // operator-imposed limits so we can push more than one message.
        $properties['queue_properties'] = [];
        $properties['queue_force_declare'] = true;
        $properties['queue_durable'] = false;
        $properties['queue_auto_delete'] = false;
        $properties['queue_exclusive'] = false;

        $config['properties'][$key] = $properties;

        return $config;
    }

    private function safeDeleteQueue(string $queue): void
    {
        try {
            $properties = $this->amqpConfig['properties'][$this->amqpConfig['use']];

            $connection = new AMQPStreamConnection(
                $properties['host'],
                (int) $properties['port'],
                $properties['username'],
                $properties['password'],
                $properties['vhost']
            );

            $channel = $connection->channel();
            $channel->queue_delete($queue);
            $channel->close();
            $connection->close();
        } catch (\Throwable $e) {
            // ignore — queue may not exist or broker may be unreachable in teardown
        }
    }

    private function isRabbitMqReachable(): bool
    {
        $socket = @fsockopen($this->host(), $this->port(), $errno, $errstr, 2);
        if ($socket) {
            fclose($socket);
            return true;
        }

        return false;
    }

    private function loadEnvFile(): void
    {
        // pkg/.env (project root, 5 directories above this file)
        $envFile = dirname(__DIR__, 5).'/.env';
        if (!is_file($envFile)) {
            return;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }

    private function envValue(string $key, $default)
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}
