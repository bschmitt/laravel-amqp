<?php

namespace Bschmitt\Amqp\Test\Support;

use Bschmitt\Amqp\Core\Amqp;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Base test case for {@see Bschmitt\Amqp\Console\Commands} unit tests.
 *
 * Wires up a minimal Illuminate Container with a mocked {@see Amqp} bound
 * to it so commands can be instantiated, executed via Symfony's
 * {@see CommandTester}, and asserted against without touching a broker.
 */
abstract class CommandTestCase extends TestCase
{
    /** @var Container */
    protected $container;

    /** @var \Mockery\MockInterface|Amqp */
    protected $amqp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        Container::setInstance($this->container);

        $this->container->instance('config', new Repository([
            'amqp' => include dirname(__DIR__, 2).'/config/amqp.php',
        ]));

        $this->amqp = Mockery::mock(Amqp::class);

        // Bind under both keys that production code resolves Amqp through.
        $this->container->instance('Amqp', $this->amqp);
        $this->container->instance(Amqp::class, $this->amqp);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * Execute an Illuminate console command via Symfony's CommandTester.
     *
     * @param Command              $command
     * @param array<string, mixed> $input  CommandTester input (arguments + options).
     *                                     Use the standard Symfony shape, e.g.
     *                                     `['queue' => 'foo', '--handler' => 'X']`.
     * @return array{status:int, output:string}
     */
    protected function runCommand(Command $command, array $input = []): array
    {
        $command->setLaravel($this->container);

        $tester = new CommandTester($command);
        $tester->execute($input);

        return [
            'status' => $tester->getStatusCode(),
            'output' => $tester->getDisplay(),
        ];
    }
}
