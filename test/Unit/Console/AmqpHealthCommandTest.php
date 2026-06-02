<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpHealthCommand;
use Bschmitt\Amqp\Support\HealthCheck;
use Bschmitt\Amqp\Support\HealthState;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpHealthCommandTest extends CommandTestCase
{
    /** @var HealthState */
    private $state;

    protected function setUp(): void
    {
        parent::setUp();
        HealthState::reset();
        $this->state = new HealthState();
        $this->container->instance(HealthState::class, $this->state);
        $this->container->bind(HealthCheck::class, function () {
            return new HealthCheck($this->state);
        });
    }

    protected function tearDown(): void
    {
        HealthState::reset();
        parent::tearDown();
    }

    public function testReadyProbeSucceedsWhenReady(): void
    {
        $this->state->markReady();

        $result = $this->runCommand(new AmqpHealthCommand());

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $payload = json_decode($result['output'], true);
        $this->assertTrue($payload['ok']);
        $this->assertSame('readiness', $payload['kind']);
    }

    public function testReadyProbeFailsWhenNotReady(): void
    {
        $result = $this->runCommand(new AmqpHealthCommand());

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $payload = json_decode($result['output'], true);
        $this->assertFalse($payload['ok']);
    }

    public function testLiveProbeSucceedsWhenAliveEvenIfNotReady(): void
    {
        $result = $this->runCommand(new AmqpHealthCommand(), [
            '--probe' => 'live',
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $payload = json_decode($result['output'], true);
        $this->assertSame('liveness', $payload['kind']);
        $this->assertTrue($payload['ok']);
    }

    public function testAllFlagReturnsSnapshot(): void
    {
        $this->state->markReady();

        $result = $this->runCommand(new AmqpHealthCommand(), [
            '--all' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $payload = json_decode($result['output'], true);
        $this->assertArrayHasKey('liveness', $payload);
        $this->assertArrayHasKey('readiness', $payload);
        $this->assertArrayHasKey('state', $payload);
    }

    public function testStateFileOptionHydratesFromDisk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'amqp_health_cli_');
        try {
            $persistent = new HealthState($path);
            $persistent->markReady('persisted');

            // Use a fresh, non-ready in-memory state and let the CLI hydrate it.
            $fresh = new HealthState();
            $this->container->instance(HealthState::class, $fresh);
            $this->container->bind(HealthCheck::class, function () use ($fresh) {
                return new HealthCheck($fresh);
            });

            $result = $this->runCommand(new AmqpHealthCommand(), [
                '--state-file' => $path,
            ]);

            $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
            $this->assertTrue($fresh->isReady());
        } finally {
            @unlink($path);
        }
    }
}
