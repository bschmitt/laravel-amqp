<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpConsumeCommand;
use Bschmitt\Amqp\Console\Commands\AmqpListenCommand;
use Bschmitt\Amqp\Console\Commands\AmqpPublishCommand;
use Bschmitt\Amqp\Console\Commands\AmqpPurgeCommand;
use Bschmitt\Amqp\Console\Commands\AmqpWorkCommand;
use Bschmitt\Amqp\Providers\AmqpServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * The service provider extends {@see \Illuminate\Support\ServiceProvider},
 * whose `commands()` method requires a full Laravel application (with the
 * `artisan` event listener wired up). Rather than spinning up a full
 * Application here we exercise the lighter contract: verify each command
 * class is well-formed and DI-resolvable through a bare container, which
 * is the real failure mode users would hit in practice if a constructor
 * mismatch slipped in.
 */
class AmqpServiceProviderCommandsTest extends TestCase
{
    public function testEveryRegisteredCommandIsResolvableThroughTheContainer(): void
    {
        $container = new Container();
        $container->instance('config', new Repository([
            'amqp' => include dirname(__DIR__, 3).'/config/amqp.php',
        ]));

        (new AmqpServiceProvider($container))->register();

        foreach ($this->commandClasses() as $class) {
            $command = $container->make($class);
            $this->assertInstanceOf($class, $command, sprintf(
                'Command [%s] should be resolvable from the container without errors.',
                $class
            ));
        }
    }

    /**
     * @return array<int, class-string>
     */
    private function commandClasses(): array
    {
        return [
            AmqpWorkCommand::class,
            AmqpConsumeCommand::class,
            AmqpListenCommand::class,
            AmqpPublishCommand::class,
            AmqpPurgeCommand::class,
        ];
    }
}
