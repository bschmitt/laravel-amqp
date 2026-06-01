<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\QueueProfile;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use InvalidArgumentException;

class QueueProfileTest extends BaseTestCase
{
    public function testQuorumProfileSetsQueueType(): void
    {
        $props = QueueProfile::quorum()->toQueueProperties();
        $this->assertSame('quorum', $props['x-queue-type']);
    }

    public function testPriorityProfileSetsMaxPriority(): void
    {
        $props = QueueProfile::priority(5)->toQueueProperties();
        $this->assertSame(5, $props['x-max-priority']);
    }

    public function testQuorumWithPriorityMergesArguments(): void
    {
        $props = QueueProfile::quorumWithPriority(3)->toQueueProperties();
        $this->assertSame('quorum', $props['x-queue-type']);
        $this->assertSame(3, $props['x-max-priority']);
    }

    public function testMergeIntoPropertiesBag(): void
    {
        $merged = QueueProfile::priority(2)->mergeInto(['queue' => 'jobs']);
        $this->assertSame(2, $merged['queue_properties']['x-max-priority']);
        $this->assertSame('jobs', $merged['queue']);
    }

    public function testInvalidPriorityRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QueueProfile::priority(0);
    }
}
