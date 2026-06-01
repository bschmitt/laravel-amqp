<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Providers\AmqpServiceProvider;
use Bschmitt\Amqp\Queue\AmqpConnector;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Mockery;

/**
 * Verifies that {@see AmqpServiceProvider::boot()} wires the `amqp` driver
 * into Laravel's queue manager via `QueueManager::extend()`.
 *
 * This is the single most user-facing concern of the package — without it,
 * `php artisan queue:work amqp` cannot work.
 */
class AmqpServiceProviderQueueTest extends BaseTestCase
{
    public function testBootRegistersAmqpDriverWhenQueueManagerIsBound(): void
    {
        $container = $this->buildContainer();

        $manager = Mockery::mock(FakeQueueManager::class);
        $manager->shouldReceive('extend')
            ->once()
            ->with('amqp', Mockery::on(function ($closure) {
                $this->assertIsCallable($closure);
                $this->assertInstanceOf(AmqpConnector::class, $closure());

                return true;
            }));

        // Bind via singleton so make('queue') goes through the full resolve
        // pipeline (instance() short-circuits and skips resolving callbacks).
        $container->singleton('queue', function () use ($manager) {
            return $manager;
        });

        (new AmqpServiceProvider($container))->boot();

        $container->make('queue');
    }

    public function testBootIsSafeWhenQueueManagerIsNotBound(): void
    {
        $container = $this->buildContainer();

        (new AmqpServiceProvider($container))->boot();

        $this->assertFalse($container->bound('queue'));
    }

    public function testBootIsSafeWhenManagerLacksExtendMethod(): void
    {
        $container = $this->buildContainer();
        $container->singleton('queue', function () {
            return new \stdClass();
        });

        (new AmqpServiceProvider($container))->boot();

        // Resolving callback should be a no-op (no method_exists($manager, 'extend')).
        $this->assertInstanceOf(\stdClass::class, $container->make('queue'));
    }

    private function buildContainer(): Container
    {
        $container = new Container();
        $container->instance('config', new Repository([
            'amqp' => include dirname(__DIR__, 2).'/config/amqp.php',
        ]));

        return $container;
    }
}

/**
 * Minimal stub mirroring the slice of Illuminate\Queue\QueueManager we
 * depend on. Lets us mock `extend()` without dragging in the full queue
 * factory contract.
 */
abstract class FakeQueueManager
{
    abstract public function extend(string $driver, \Closure $resolver);
}
