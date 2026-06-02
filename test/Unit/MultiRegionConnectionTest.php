<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\MultiRegionConnection;
use PHPUnit\Framework\TestCase;

class MultiRegionConnectionTest extends TestCase
{
    public function testPicksFirstRegionWhenNoPrimary(): void
    {
        $mr = new MultiRegionConnection(['us', 'eu', 'apac']);
        $this->assertSame('us', $mr->pick());
    }

    public function testPrimaryRegionIsTried(): void
    {
        $mr = new MultiRegionConnection(['us', 'eu', 'apac'], 'eu');
        $this->assertSame('eu', $mr->pick());
        $this->assertSame(['eu', 'us', 'apac'], $mr->each());
    }

    public function testRegionTagMatchingByPartial(): void
    {
        $mr = new MultiRegionConnection(['production-us', 'production-eu', 'production-apac'], null, 30, 'us-east-1');
        $this->assertSame('production-us', $mr->pick());
    }

    public function testFailedRegionIsCooledDown(): void
    {
        $mr = new MultiRegionConnection(['us', 'eu', 'apac'], null, 60);
        $mr->markFailed('us');

        $this->assertSame('eu', $mr->pick());
    }

    public function testReturnsNullWhenAllRegionsCooldown(): void
    {
        $mr = new MultiRegionConnection(['us', 'eu'], null, 60);
        $mr->markFailed('us');
        $mr->markFailed('eu');

        $this->assertNull($mr->pick());
    }

    public function testMarkHealthyClearsCooldown(): void
    {
        $mr = new MultiRegionConnection(['us', 'eu'], null, 60);
        $mr->markFailed('us');
        $mr->markHealthy('us');

        $this->assertSame('us', $mr->pick());
    }

    public function testWithFailoverRetriesUntilSuccess(): void
    {
        $mr = new MultiRegionConnection(['us', 'eu', 'apac']);
        $attempts = [];

        $result = $mr->withFailover(function ($region) use (&$attempts) {
            $attempts[] = $region;
            if ($region === 'us' || $region === 'eu') {
                throw new \RuntimeException("region {$region} down");
            }
            return 'ok-' . $region;
        });

        $this->assertSame('ok-apac', $result);
        $this->assertSame(['us', 'eu', 'apac'], $attempts);
    }

    public function testWithFailoverRethrowsLastExceptionWhenAllFail(): void
    {
        $mr = new MultiRegionConnection(['us', 'eu']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('eu down');

        $mr->withFailover(function ($region) {
            throw new \RuntimeException("{$region} down");
        });
    }

    public function testWithFailoverThrowsWhenAllRegionsCooledDown(): void
    {
        $mr = new MultiRegionConnection(['us', 'eu'], null, 60);
        $mr->markFailed('us');
        $mr->markFailed('eu');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cool-down');

        $mr->withFailover(function ($region) {
            return $region;
        });
    }
}
