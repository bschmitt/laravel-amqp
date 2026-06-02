<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpDiffCommand;
use Bschmitt\Amqp\Contracts\MessageStoreInterface;
use Bschmitt\Amqp\Support\InMemoryMessageStore;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpDiffCommandTest extends CommandTestCase
{
    /** @var InMemoryMessageStore */
    private $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryMessageStore();
        $this->container->instance(MessageStoreInterface::class, $this->store);
    }

    public function testFailsWhenEitherSideIsMissing(): void
    {
        $id = $this->store->append('published', 'r', '{}', [], []);

        $result = $this->runCommand(new AmqpDiffCommand(), [
            'left' => $id,
            'right' => 'missing_id',
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('not found', $result['output']);
    }

    public function testHumanDiffOutput(): void
    {
        $left = $this->store->append(
            'published',
            'orders.created',
            '{"total":10}',
            ['content_type' => 'application/json'],
            ['x-correlation-id' => 'corr_a']
        );
        $right = $this->store->append(
            'published',
            'orders.created',
            '{"total":20}',
            ['content_type' => 'application/json'],
            ['x-correlation-id' => 'corr_a', 'x-retry-attempt' => 1]
        );

        $result = $this->runCommand(new AmqpDiffCommand(), [
            'left' => $left,
            'right' => $right,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('── body ──', $result['output']);
        $this->assertStringContainsString('~ /total : 10 -> 20', $result['output']);
        $this->assertStringContainsString('── headers ──', $result['output']);
        $this->assertStringContainsString('+ x-retry-attempt = 1', $result['output']);
        $this->assertStringContainsString('── properties ──', $result['output']);
        $this->assertStringContainsString('(identical)', $result['output']);
    }

    public function testJsonDiffOutputIsParsable(): void
    {
        $left = $this->store->append('published', 'r', '{"a":1}', [], []);
        $right = $this->store->append('published', 'r', '{"a":2}', [], []);

        $result = $this->runCommand(new AmqpDiffCommand(), [
            'left' => $left,
            'right' => $right,
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertIsArray($payload);
        $this->assertSame($left, $payload['left']['id']);
        $this->assertSame($right, $payload['right']['id']);
        $this->assertSame('json', $payload['body']['format']);
        $this->assertFalse($payload['body']['identical']);
        $this->assertSame('/a', $payload['body']['changes'][0]['path']);
    }
}
