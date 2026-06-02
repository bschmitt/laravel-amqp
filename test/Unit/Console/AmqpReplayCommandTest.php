<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpReplayCommand;
use Bschmitt\Amqp\Contracts\MessageStoreInterface;
use Bschmitt\Amqp\Support\InMemoryMessageStore;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Mockery;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpReplayCommandTest extends CommandTestCase
{
    /** @var InMemoryMessageStore */
    private $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryMessageStore();
        $this->container->instance(MessageStoreInterface::class, $this->store);
    }

    public function testEmptyStoreReportsNoMatches(): void
    {
        $result = $this->runCommand(new AmqpReplayCommand(), [
            '--routing' => 'orders.created',
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertSame(0, $payload['matched']);
        $this->assertSame(0, $payload['replayed']);
        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
    }

    public function testDryRunDoesNotPublish(): void
    {
        $this->store->append('published', 'orders.created', '{}', ['correlation_id' => 'c1'], []);
        $this->store->append('published', 'orders.created', '{}', ['correlation_id' => 'c2'], []);

        $this->amqp->shouldNotReceive('publish');

        $result = $this->runCommand(new AmqpReplayCommand(), [
            '--routing' => 'orders.created',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame(2, $payload['matched']);
        $this->assertSame(2, $payload['replayed']);
    }

    public function testReplayCallsAmqpPublishWithStoredProperties(): void
    {
        $this->store->append(
            'published',
            'orders.created',
            '{"orderId":"o-1"}',
            ['correlation_id' => 'corr_a', 'content_type' => 'application/json'],
            ['x-correlation-id' => 'corr_a']
        );

        $this->amqp->shouldReceive('publish')
            ->once()
            ->withArgs(function ($routing, $body, $props) {
                return $routing === 'orders.created'
                    && $body === '{"orderId":"o-1"}'
                    && isset($props['application_headers']['x-correlation-id'])
                    && $props['application_headers']['x-correlation-id'] === 'corr_a'
                    && ($props['content_type'] ?? null) === 'application/json'
                    && ($props['correlation_id'] ?? null) === 'corr_a';
            });

        $result = $this->runCommand(new AmqpReplayCommand(), [
            '--routing' => 'orders.created',
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertSame(1, $payload['matched']);
        $this->assertSame(1, $payload['replayed']);
        $this->assertSame(0, $payload['failed']);
        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
    }

    public function testTargetAndExchangeOverridesAreApplied(): void
    {
        $this->store->append('published', 'orders.created', 'body', [], []);

        $this->amqp->shouldReceive('publish')
            ->once()
            ->withArgs(function ($routing, $body, $props) {
                return $routing === 'orders.recovery'
                    && ($props['exchange'] ?? null) === 'recovery-x';
            });

        $result = $this->runCommand(new AmqpReplayCommand(), [
            '--routing' => 'orders.created',
            '--target' => 'orders.recovery',
            '--exchange' => 'recovery-x',
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertSame('orders.recovery', $payload['target']);
        $this->assertSame('recovery-x', $payload['exchange']);
        $this->assertSame(1, $payload['replayed']);
    }

    public function testFailuresAreReportedAndExitNonZero(): void
    {
        $this->store->append('published', 'orders.created', 'body', [], []);

        $this->amqp->shouldReceive('publish')
            ->once()
            ->andThrow(new \RuntimeException('broker down'));

        $result = $this->runCommand(new AmqpReplayCommand(), [
            '--routing' => 'orders.created',
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertSame(1, $payload['failed']);
        $this->assertStringContainsString('broker down', $payload['failures'][0]['error']);
        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
    }

    public function testReplayByIdBypassesFilters(): void
    {
        $kept = $this->store->append('published', 'orders.created', 'a', [], []);
        $this->store->append('published', 'orders.shipped', 'b', [], []);

        $this->amqp->shouldReceive('publish')->once()
            ->with('orders.created', 'a', Mockery::any());

        $result = $this->runCommand(new AmqpReplayCommand(), [
            '--id' => [$kept],
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertSame(1, $payload['matched']);
        $this->assertSame(1, $payload['replayed']);
    }
}
