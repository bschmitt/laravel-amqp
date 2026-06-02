<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\RpcLatencyRecorder;
use Bschmitt\Amqp\Test\Support\BaseTestCase;

class RpcLatencyRecorderTest extends BaseTestCase
{
    public function testRecordTracksCountsAndAverages(): void
    {
        $rec = new RpcLatencyRecorder();
        $rec->record('UserService::getUser', 10.0);
        $rec->record('UserService::getUser', 20.0);
        $rec->record('UserService::getUser', 30.0);

        $snap = $rec->snapshot();
        $this->assertSame(3, $snap['UserService::getUser']['count']);
        $this->assertSame(0, $snap['UserService::getUser']['failed']);
        $this->assertEqualsWithDelta(20.0, $snap['UserService::getUser']['avg_ms'], 0.001);
        $this->assertSame(10.0, $snap['UserService::getUser']['min_ms']);
        $this->assertSame(30.0, $snap['UserService::getUser']['max_ms']);
    }

    public function testFailedCallsBumpFailureCount(): void
    {
        $rec = new RpcLatencyRecorder();
        $rec->record('svc', 1.0, false);
        $rec->record('svc', 1.0, true);
        $rec->record('svc', 1.0, true);

        $snap = $rec->snapshot();
        $this->assertSame(3, $snap['svc']['count']);
        $this->assertSame(2, $snap['svc']['failed']);
        $this->assertSame(1, $snap['svc']['success']);
        $this->assertEqualsWithDelta(2 / 3, $snap['svc']['error_rate'], 0.001);
    }

    public function testPercentileFallsIntoNextBucket(): void
    {
        $rec = new RpcLatencyRecorder();
        for ($i = 0; $i < 90; $i++) {
            $rec->record('svc', 4.0);
        }
        for ($i = 0; $i < 10; $i++) {
            $rec->record('svc', 750.0);
        }

        $snap = $rec->snapshot();
        $this->assertSame(5.0, $snap['svc']['p50_ms']);
        $this->assertSame(1000.0, $snap['svc']['p95_ms']);
    }

    public function testValuesAboveLastBucketSurfaceAsInfBucket(): void
    {
        $rec = new RpcLatencyRecorder();
        $rec->record('svc', 25000.0);

        $snap = $rec->snapshot();
        $this->assertSame(1, $snap['svc']['count']);
        $this->assertSame(25000.0, $snap['svc']['max_ms']);
        $this->assertSame(10000.0, $snap['svc']['p99_ms']);
    }

    public function testNegativeValuesClampedToZero(): void
    {
        $rec = new RpcLatencyRecorder();
        $rec->record('svc', -5.0);

        $snap = $rec->snapshot();
        $this->assertSame(0.0, $snap['svc']['min_ms']);
        $this->assertSame(0.0, $snap['svc']['max_ms']);
    }

    public function testForReturnsSpecificKey(): void
    {
        $rec = new RpcLatencyRecorder();
        $rec->record('a', 1.0);
        $rec->record('b', 1.0);

        $this->assertNotNull($rec->for('a'));
        $this->assertNull($rec->for('missing'));
    }

    public function testResetClearsState(): void
    {
        $rec = new RpcLatencyRecorder();
        $rec->record('svc', 1.0);
        $rec->reset();
        $this->assertSame([], $rec->snapshot());
    }
}
