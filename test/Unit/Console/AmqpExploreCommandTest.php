<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\Commands\AmqpExploreCommand;
use Bschmitt\Amqp\Contracts\MessageStoreInterface;
use Bschmitt\Amqp\Support\InMemoryMessageStore;
use Bschmitt\Amqp\Test\Support\CommandTestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AmqpExploreCommandTest extends CommandTestCase
{
    /** @var InMemoryMessageStore */
    private $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryMessageStore();
        $this->container->instance(MessageStoreInterface::class, $this->store);
    }

    public function testEmptyStoreReturnsSuccessWithWarning(): void
    {
        $result = $this->runCommand(new AmqpExploreCommand());

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('No messages matched', $result['output']);
    }

    public function testListRendersHumanTableWithMostRecentFirst(): void
    {
        $this->seed('orders.created', 'corr_a', '{"orderId":"o-1"}');
        usleep(1000);
        $this->seed('orders.shipped', 'corr_b', '{"orderId":"o-2"}');

        $result = $this->runCommand(new AmqpExploreCommand());

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('orders.created', $result['output']);
        $this->assertStringContainsString('orders.shipped', $result['output']);

        $shippedAt = strpos($result['output'], 'orders.shipped');
        $createdAt = strpos($result['output'], 'orders.created');
        $this->assertNotFalse($shippedAt);
        $this->assertNotFalse($createdAt);
        $this->assertLessThan($createdAt, $shippedAt);
    }

    public function testFilterByRoutingNarrowsResults(): void
    {
        $this->seed('orders.created', 'corr_a', '{}');
        $this->seed('orders.shipped', 'corr_b', '{}');

        $result = $this->runCommand(new AmqpExploreCommand(), [
            '--routing' => 'orders.shipped',
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertIsArray($payload);
        $this->assertCount(1, $payload);
        $this->assertSame('orders.shipped', $payload[0]['routing']);
    }

    public function testFilterByCorrelationMatchesPropertiesAndHeaders(): void
    {
        $this->seed('orders.created', 'corr_a', '{}');
        $this->seed('orders.created', 'corr_b', '{}', [], ['x-correlation-id' => 'corr_b']);

        $result = $this->runCommand(new AmqpExploreCommand(), [
            '--correlation' => 'corr_b',
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertCount(1, $payload);
        $this->assertSame('corr_b', $payload[0]['correlation']);
    }

    public function testShowOneReturnsFailureWhenMissing(): void
    {
        $result = $this->runCommand(new AmqpExploreCommand(), [
            '--id' => 'missing_id',
        ]);

        $this->assertSame(SymfonyCommand::FAILURE, $result['status']);
        $this->assertStringContainsString('not found', $result['output']);
    }

    public function testShowOneRendersAllSectionsForExistingEntry(): void
    {
        $id = $this->store->append(
            'published',
            'orders.created',
            '{"orderId":"o-1"}',
            ['content_type' => 'application/json', 'correlation_id' => 'corr_a'],
            ['x-correlation-id' => 'corr_a']
        );

        $result = $this->runCommand(new AmqpExploreCommand(), [
            '--id' => $id,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $result['status']);
        $this->assertStringContainsString('orders.created', $result['output']);
        $this->assertStringContainsString('-- body --', $result['output']);
        $this->assertStringContainsString('-- properties --', $result['output']);
        $this->assertStringContainsString('-- headers --', $result['output']);
    }

    public function testLimitCapsResults(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seed('orders.created', 'corr_' . $i, '{}');
        }

        $result = $this->runCommand(new AmqpExploreCommand(), [
            '--limit' => 2,
            '--json' => true,
        ]);

        $payload = json_decode($result['output'], true);
        $this->assertCount(2, $payload);
    }

    /**
     * @param string                $routing
     * @param string                $correlation
     * @param string                $body
     * @param array<string, mixed>  $extraProperties
     * @param array<string, mixed>  $extraHeaders
     * @return string Message id
     */
    private function seed(
        string $routing,
        string $correlation,
        string $body,
        array $extraProperties = [],
        array $extraHeaders = []
    ): string {
        $properties = array_merge(
            ['correlation_id' => $correlation],
            $extraProperties
        );
        return $this->store->append('published', $routing, $body, $properties, $extraHeaders);
    }
}
