<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\CorrelationChain;
use Bschmitt\Amqp\Support\CorrelationContext;
use Bschmitt\Amqp\Support\InMemoryMessageStore;
use Bschmitt\Amqp\Test\Support\BaseTestCase;

class CorrelationChainTest extends BaseTestCase
{
    public function testEntriesForReturnsOnlyMatchingCorrelationIdOrderedByTime(): void
    {
        $store = new InMemoryMessageStore();

        $this->seed($store, 'orders.created', 'corr_a', 'msg_1');
        usleep(1000);
        $this->seed($store, 'invoices.created', 'corr_b', 'msg_x');
        usleep(1000);
        $this->seed($store, 'orders.shipped', 'corr_a', 'msg_2', 'msg_1');

        $chain = new CorrelationChain($store);
        $entries = $chain->entriesFor('corr_a');

        $this->assertCount(2, $entries);
        $this->assertSame('msg_1', $entries[0]['properties']['message_id']);
        $this->assertSame('msg_2', $entries[1]['properties']['message_id']);
    }

    public function testEntriesForFallsBackToHeaderWhenPropertyMissing(): void
    {
        $store = new InMemoryMessageStore();

        // No correlation_id property, only the application header.
        $store->append('published', 'orders.created', '{}', [], [
            CorrelationContext::HEADER => 'corr_header',
        ]);
        $store->append('published', 'orders.other', '{}', ['correlation_id' => 'corr_other'], []);

        $chain = new CorrelationChain($store);
        $entries = $chain->entriesFor('corr_header');

        $this->assertCount(1, $entries);
        $this->assertSame('orders.created', $entries[0]['routing']);
    }

    public function testTreeAttachesChildrenViaCausationHeader(): void
    {
        $store = new InMemoryMessageStore();
        $this->seed($store, 'orders.created', 'corr_1', 'msg_root');
        $this->seed($store, 'orders.shipped', 'corr_1', 'msg_child_a', 'msg_root');
        $this->seed($store, 'orders.invoiced', 'corr_1', 'msg_child_b', 'msg_root');
        $this->seed($store, 'orders.delivered', 'corr_1', 'msg_grand', 'msg_child_a');

        $tree = (new CorrelationChain($store))->tree('corr_1');

        $this->assertCount(1, $tree, 'one root expected');
        $root = $tree[0];
        $this->assertSame('msg_root', $root['entry']['properties']['message_id']);
        $this->assertCount(2, $root['children']);

        $childA = $root['children'][0];
        $this->assertSame('msg_child_a', $childA['entry']['properties']['message_id']);
        $this->assertCount(1, $childA['children']);
        $this->assertSame('msg_grand', $childA['children'][0]['entry']['properties']['message_id']);

        $childB = $root['children'][1];
        $this->assertSame('msg_child_b', $childB['entry']['properties']['message_id']);
        $this->assertCount(0, $childB['children']);
    }

    public function testTreeOrphansBecomeRootsWhenCausationUnknown(): void
    {
        $store = new InMemoryMessageStore();

        // Both entries refer to causation ids whose parents were never
        // recorded — they should each surface as a root, not be dropped.
        $this->seed($store, 'orders.shipped', 'corr_1', 'msg_a', 'missing_parent');
        $this->seed($store, 'orders.refunded', 'corr_1', 'msg_b', 'also_missing');

        $tree = (new CorrelationChain($store))->tree('corr_1');

        $this->assertCount(2, $tree);
    }

    public function testRenderEmitsAsciiTree(): void
    {
        $store = new InMemoryMessageStore();
        $this->seed($store, 'orders.created', 'corr_1', 'msg_root');
        $this->seed($store, 'orders.shipped', 'corr_1', 'msg_a', 'msg_root');
        $this->seed($store, 'orders.invoiced', 'corr_1', 'msg_b', 'msg_root');

        $chain = new CorrelationChain($store);
        $output = $chain->render($chain->tree('corr_1'));

        $this->assertStringContainsString('orders.created', $output);
        $this->assertStringContainsString('├──', $output);
        $this->assertStringContainsString('└──', $output);
        $this->assertStringContainsString('orders.shipped', $output);
        $this->assertStringContainsString('orders.invoiced', $output);
    }

    public function testSummarizeCountsAndTimings(): void
    {
        $store = new InMemoryMessageStore();
        $this->seed($store, 'orders.created', 'corr_1', 'msg_root', null, 'published');
        usleep(2000);
        $this->seed($store, 'orders.created', 'corr_1', 'msg_root_c', null, 'consumed');
        usleep(2000);
        $this->seed($store, 'orders.shipped', 'corr_1', 'msg_a', 'msg_root', 'published');

        $summary = (new CorrelationChain($store))->summarize('corr_1');

        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['published']);
        $this->assertSame(1, $summary['consumed']);
        $this->assertGreaterThan(0.0, $summary['duration_ms']);
        $this->assertArrayHasKey('orders.created', $summary['routings']);
        $this->assertSame(2, $summary['routings']['orders.created']);
    }

    public function testSummarizeForUnknownCorrelationReturnsZeroes(): void
    {
        $store = new InMemoryMessageStore();
        $summary = (new CorrelationChain($store))->summarize('nope');

        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['published']);
        $this->assertSame(0, $summary['consumed']);
        $this->assertSame(0.0, $summary['duration_ms']);
        $this->assertNull($summary['first_at']);
    }

    /**
     * @param InMemoryMessageStore $store
     * @param string $routing
     * @param string $correlationId
     * @param string $messageId
     * @param string|null $causation
     * @param string $direction
     * @return void
     */
    private function seed(
        InMemoryMessageStore $store,
        string $routing,
        string $correlationId,
        string $messageId,
        ?string $causation = null,
        string $direction = 'published'
    ): void {
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

        $store->append($direction, $routing, '{}', $properties, $headers);
    }
}
