<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\InMemoryMessageStore;
use Bschmitt\Amqp\Test\Support\BaseTestCase;

class InMemoryMessageStoreTest extends BaseTestCase
{
    public function testAppendReturnsUniqueIds(): void
    {
        $store = new InMemoryMessageStore();
        $id1 = $store->append('published', 'orders', 'body-1');
        $id2 = $store->append('published', 'orders', 'body-2');

        $this->assertNotSame($id1, $id2);
        $this->assertSame(2, $store->count());
    }

    public function testFindReturnsAppendedEntry(): void
    {
        $store = new InMemoryMessageStore();
        $id = $store->append('consumed', 'orders', 'body', ['content_type' => 'application/json']);

        $entry = $store->find($id);
        $this->assertNotNull($entry);
        $this->assertSame('consumed', $entry['direction']);
        $this->assertSame('orders', $entry['routing']);
        $this->assertSame('body', $entry['body']);
    }

    public function testAllAppliesFilterAndLimit(): void
    {
        $store = new InMemoryMessageStore();
        $store->append('published', 'orders', 'a');
        $store->append('consumed',  'orders', 'b');
        $store->append('published', 'invoices', 'c');

        $this->assertCount(2, $store->all(['direction' => 'published']));
        $this->assertCount(1, $store->all(['routing' => 'invoices']));
        $this->assertCount(1, $store->all(['direction' => 'published'], 1));
    }

    public function testPurgeClearsState(): void
    {
        $store = new InMemoryMessageStore();
        $store->append('published', 'orders', 'a');
        $store->purge();
        $this->assertSame(0, $store->count());
    }

    public function testCountWithFilter(): void
    {
        $store = new InMemoryMessageStore();
        $store->append('published', 'orders', 'a');
        $store->append('published', 'orders', 'b');
        $store->append('consumed', 'orders', 'c');

        $this->assertSame(2, $store->count(['direction' => 'published']));
        $this->assertSame(0, $store->count(['direction' => 'failed']));
    }
}
