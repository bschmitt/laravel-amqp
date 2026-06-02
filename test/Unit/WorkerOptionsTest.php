<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\WorkerOptions;
use Bschmitt\Amqp\Test\Support\BaseTestCase;

class WorkerOptionsTest extends BaseTestCase
{
    public function testThroughputSetsPrefetch(): void
    {
        $props = WorkerOptions::throughput(25)->mergeInto([]);
        $this->assertTrue($props['qos']);
        $this->assertSame(25, $props['qos_prefetch_count']);
    }

    public function testLowLatencyUsesPrefetchOne(): void
    {
        $props = WorkerOptions::lowLatency()->mergeInto([]);
        $this->assertSame(1, $props['qos_prefetch_count']);
    }

    public function testPersistentConnectionSetsPoolKey(): void
    {
        $props = WorkerOptions::throughput()->persistentConnection('worker-1')->mergeInto([]);
        $this->assertSame('worker-1', $props['__worker_persistent_pool']);
    }
}
