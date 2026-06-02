<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpSchemaCommand;
use Bschmitt\Amqp\Contracts\MessageContractInterface;
use Bschmitt\Amqp\Contracts\MessageStoreInterface;
use Bschmitt\Amqp\Support\InMemoryMessageStore;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpSchemaCommandTest extends CommandTestCase
{
    /** @var InMemoryMessageStore */
    private $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryMessageStore();
        $this->container->instance(MessageStoreInterface::class, $this->store);
    }

    public function testReturnsFailureForUnknownClass(): void
    {
        $result = $this->runCommand(new AmqpSchemaCommand(), [
            'contract' => '\\Bogus\\Class\\Name',
            '--payload' => '{}',
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('does not exist', $result['output']);
    }

    public function testReturnsFailureWhenClassDoesNotImplementContract(): void
    {
        $result = $this->runCommand(new AmqpSchemaCommand(), [
            'contract' => \stdClass::class,
            '--payload' => '{}',
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('must implement', $result['output']);
    }

    public function testValidPayloadEmitsOkAndZeroExit(): void
    {
        $result = $this->runCommand(new AmqpSchemaCommand(), [
            'contract' => AmqpSchemaCommandValidContract::class,
            '--payload' => '{"orderId":"o-1","total":9.99}',
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('OK', $result['output']);
    }

    public function testInvalidPayloadListsErrorsAndExitsNonZero(): void
    {
        $result = $this->runCommand(new AmqpSchemaCommand(), [
            'contract' => AmqpSchemaCommandValidContract::class,
            '--payload' => '{"orderId":"o-1"}',
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('Invalid', $result['output']);
        $this->assertStringContainsString('total', $result['output']);
    }

    public function testJsonOutputContainsErrorsAndSchemaWhenRequested(): void
    {
        $result = $this->runCommand(new AmqpSchemaCommand(), [
            'contract' => AmqpSchemaCommandValidContract::class,
            '--payload' => '{}',
            '--json' => true,
            '--show-schema' => true,
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $payload = json_decode($result['output'], true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['valid']);
        $this->assertNotEmpty($payload['errors']);
        $this->assertArrayHasKey('schema', $payload);
        $this->assertSame(AmqpSchemaCommandValidContract::class, $payload['contract']);
    }

    public function testPayloadFromMessageStore(): void
    {
        $id = $this->store->append(
            'published',
            'orders.created',
            '{"orderId":"o-1","total":1.0}',
            [],
            []
        );

        $result = $this->runCommand(new AmqpSchemaCommand(), [
            'contract' => AmqpSchemaCommandValidContract::class,
            '--message-id' => $id,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('OK', $result['output']);
    }

    public function testFailsWhenContractMissingSchemaMethod(): void
    {
        $result = $this->runCommand(new AmqpSchemaCommand(), [
            'contract' => AmqpSchemaCommandUntypedContract::class,
            '--payload' => '{}',
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('does not declare a schema()', $result['output']);
    }
}

class AmqpSchemaCommandValidContract implements MessageContractInterface
{
    public function toPayload(): array
    {
        return [];
    }

    public static function fromPayload(array $payload)
    {
        return new self();
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['orderId', 'total'],
            'properties' => [
                'orderId' => ['type' => 'string'],
                'total' => ['type' => 'number'],
            ],
        ];
    }
}

class AmqpSchemaCommandUntypedContract implements MessageContractInterface
{
    public function toPayload(): array
    {
        return [];
    }

    public static function fromPayload(array $payload)
    {
        return new self();
    }
}
