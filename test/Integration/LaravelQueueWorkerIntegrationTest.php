<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Queue\AmqpConnector;
use Bschmitt\Amqp\Queue\AmqpQueue;
use Bschmitt\Amqp\Test\Support\LaravelQueueTestCase;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

/**
 * End-to-end exercise of the full queue stack:
 * Container -> QueueManager -> AmqpConnector -> AmqpQueue -> Worker -> Job.
 *
 * This is the closest in-process equivalent of `php artisan queue:work amqp`
 * and proves the service-provider wiring, connector, queue contract and job
 * lifecycle all cooperate against a real broker.
 */
class LaravelQueueWorkerIntegrationTest extends LaravelQueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeQueueHandler::reset();
    }

    public function testWorkerProcessesAndAcknowledgesAJob(): void
    {
        $queueName = $this->uniqueQueueName('laravel-queue-worker');

        $manager = $this->buildQueueManagerFor($queueName);

        /** @var AmqpQueue $queue */
        $queue = $manager->connection('amqp');

        $payload = json_encode([
            'id' => $this->uuid(),
            'uuid' => $this->uuid(),
            'displayName' => FakeQueueHandler::class,
            'job' => FakeQueueHandler::class.'@handle',
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'data' => ['payload' => 'hello-from-worker'],
        ]);

        $queue->pushRaw($payload, $queueName);
        usleep(150_000);

        $worker = $this->buildWorker($manager);

        $worker->runNextJob('amqp', $queueName, new WorkerOptions());

        $this->assertSame(1, FakeQueueHandler::$callCount, 'handler must run exactly once');
        $this->assertSame(['payload' => 'hello-from-worker'], FakeQueueHandler::$lastData);
        $this->assertSame(0, $queue->size($queueName), 'job must be acked after handle()');
    }

    public function testWorkerSleepsWhenQueueIsEmpty(): void
    {
        $queueName = $this->uniqueQueueName('laravel-queue-worker-empty');

        $manager = $this->buildQueueManagerFor($queueName);

        // Force declaration so size() returns 0 instead of 404.
        $this->assertSame(0, $manager->connection('amqp')->size($queueName));

        $worker = $this->buildWorker($manager);

        // sleep=0 keeps the test fast - runNextJob() returns without invoking any handler.
        $worker->runNextJob('amqp', $queueName, new WorkerOptions('default', 0, 128, 60, 0));

        $this->assertSame(0, FakeQueueHandler::$callCount);
    }

    private function buildQueueManagerFor(string $queueName): QueueManager
    {
        // Augment the container's config repository with the queue-driver entry.
        /** @var Repository $config */
        $config = $this->container->make('config');
        $config->set('queue.default', 'amqp');
        $config->set('queue.connections.amqp', [
            'driver' => 'amqp',
            'connection' => $this->amqpConfig['use'],
            'queue' => $queueName,
            'retry_after' => 90,
        ]);

        $manager = new QueueManager($this->container);
        $manager->addConnector('amqp', function () {
            return new AmqpConnector($this->container);
        });

        return $manager;
    }

    private function buildWorker(QueueManager $manager): Worker
    {
        return new Worker(
            $manager,
            new NullEventDispatcher(),
            new NullExceptionHandler(),
            function () { return false; }
        );
    }
}

/**
 * Minimal Dispatcher that silently swallows all events. The Worker fires
 * lifecycle events (Looping, JobProcessing, JobProcessed, ...) which are not
 * relevant to these assertions; this stub keeps the package vendor footprint
 * small (no illuminate/events dependency).
 */
class NullEventDispatcher implements Dispatcher
{
    public function listen($events, $listener = null) {}
    public function hasListeners($eventName) { return false; }
    public function subscribe($subscriber) {}
    public function until($event, $payload = []) { return null; }
    public function dispatch($event, $payload = [], $halt = false) { return null; }
    public function push($event, $payload = []) {}
    public function flush($event) {}
    public function forget($event) {}
    public function forgetPushed() {}
}

/**
 * Test-only job handler: counts invocations and self-ACKs through the job.
 */
class FakeQueueHandler
{
    /** @var int */
    public static $callCount = 0;

    /** @var array|null */
    public static $lastData = null;

    public static function reset(): void
    {
        self::$callCount = 0;
        self::$lastData = null;
    }

    public function handle($job, array $data): void
    {
        self::$callCount++;
        self::$lastData = $data;

        $job->delete();
    }
}

/**
 * Minimal ExceptionHandler implementation that re-throws so test failures
 * surface clearly instead of being silently swallowed by the Worker.
 */
class NullExceptionHandler implements ExceptionHandler
{
    public function report(\Throwable $e)
    {
        throw $e;
    }

    public function shouldReport(\Throwable $e)
    {
        return true;
    }

    public function render($request, \Throwable $e)
    {
        throw $e;
    }

    public function renderForConsole($output, \Throwable $e)
    {
        throw $e;
    }
}
