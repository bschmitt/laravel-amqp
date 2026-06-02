<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpTraceCommand;
use Bschmitt\Amqp\Contracts\MessageStoreInterface;
use Bschmitt\Amqp\Support\CorrelationContext;
use Bschmitt\Amqp\Support\InMemoryMessageStore;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpTraceCommandTest extends CommandTestCase
{
    /** @var InMemoryMessageStore */
    private $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryMessageStore();
        $this->container->instance(MessageStoreInterface::class, $this->store);
    }

    public function testEmptyStoreReturnsFailureWithWarning(): void
    {
        $result = $this->runCommand($this->makeCommand(), [
            'correlation_id' => 'corr_missing',
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('No messages recorded', $result['output']);
    }

    public function testMissingCorrelationIdReturnsFailureBeforeStoreLookup(): void
    {
        $result = $this->runCommand($this->makeCommand(), [
            'correlation_id' => '',
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('required', $result['output']);
    }

    public function testHumanOutputRendersAsciiTreeAndHeader(): void
    {
        $this->seed('orders.created', 'corr_1', 'msg_root', null);
        $this->seed('orders.shipped', 'corr_1', 'msg_a', 'msg_root');
        $this->seed('orders.shipped', 'corr_1', 'msg_b', 'msg_root');

        $result = $this->runCommand($this->makeCommand(), [
            'correlation_id' => 'corr_1',
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('correlation_id: corr_1', $result['output']);
        $this->assertStringContainsString('messages: 3', $result['output']);
        $this->assertStringContainsString('orders.created', $result['output']);
        $this->assertStringContainsString('orders.shipped', $result['output']);
        $this->assertStringContainsString('├──', $result['output']);
        $this->assertStringContainsString('└──', $result['output']);
    }

    public function testSummaryFlagSuppressesTreeRendering(): void
    {
        $this->seed('orders.created', 'corr_2', 'msg_1', null);
        $this->seed('orders.shipped', 'corr_2', 'msg_2', 'msg_1');

        $result = $this->runCommand($this->makeCommand(), [
            'correlation_id' => 'corr_2',
            '--summary' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('messages: 2', $result['output']);
        $this->assertStringNotContainsString('├──', $result['output']);
        $this->assertStringNotContainsString('└──', $result['output']);
    }

    public function testJsonFlagOutputsParsableJson(): void
    {
        $this->seed('orders.created', 'corr_3', 'msg_x', null);

        $result = $this->runCommand($this->makeCommand(), [
            'correlation_id' => 'corr_3',
            '--json' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);

        $payload = json_decode($result['output'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('entries', $payload);
        $this->assertArrayHasKey('tree', $payload);
        $this->assertSame('corr_3', $payload['summary']['correlation_id']);
        $this->assertCount(1, $payload['entries']);
    }

    public function testJsonOutputForEmptyStoreStillReturnsValidJson(): void
    {
        $result = $this->runCommand($this->makeCommand(), [
            'correlation_id' => 'corr_unknown',
            '--json' => true,
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $payload = json_decode($result['output'], true);
        $this->assertIsArray($payload);
        $this->assertSame('corr_unknown', $payload['correlation_id']);
        $this->assertSame([], $payload['entries']);
    }

    public function testLimitCapsRenderedEntries(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seed('orders.' . $i, 'corr_4', 'msg_' . $i, null);
        }

        $result = $this->runCommand($this->makeCommand(), [
            'correlation_id' => 'corr_4',
            '--limit' => 2,
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertCount(2, $payload['entries']);
        $this->assertCount(2, $payload['tree']);
    }

    /* ------------------------------------------------------------------ */

    private function makeCommand(): AmqpTraceCommand
    {
        return new AmqpTraceCommand();
    }

    private function seed(string $routing, string $correlationId, string $messageId, ?string $causation): void
    {
        $properties = [
            'message_id' => $messageId,
            'correlation_id' => $correlationId,
        ];
        $headers = [
            CorrelationContext::HEADER => $correlationId,
        ];
        if ($causation !== null) {
            $headers[CorrelationContext::CAUSATION_HEADER] = $causation;
        }

        $this->store->append('published', $routing, '{}', $properties, $headers);
        usleep(500);
    }
}
