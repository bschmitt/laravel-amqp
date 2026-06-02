<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Bschmitt\Amqp\Testing\FakeAmqp;
use PHPUnit\Framework\AssertionFailedError;

class FakeAmqpTest extends BaseTestCase
{
    public function testRecordsPublishes(): void
    {
        $fake = new FakeAmqp();
        $fake->publish('orders.created', '{"id":1}', ['exchange' => 'events']);
        $fake->publishLater('orders.delayed', '{"id":2}', 5000);

        $this->assertCount(2, $fake->published());
        $fake->assertPublished('orders.created');
        $fake->assertPublished('orders.delayed', function ($entry) {
            return $entry['properties']['__delay_ms'] === 5000;
        });
    }

    public function testAssertNotPublishedPasses(): void
    {
        $fake = new FakeAmqp();
        $fake->publish('orders.created', 'body');
        $fake->assertNotPublished('orders.shipped');
        $this->assertTrue(true);
    }

    public function testAssertPublishedFailsWhenNoMatch(): void
    {
        $fake = new FakeAmqp();
        $this->expectException(AssertionFailedError::class);
        $fake->assertPublished('orders.created');
    }

    public function testAssertNothingPublishedFailsWhenSomethingPublished(): void
    {
        $fake = new FakeAmqp();
        $fake->publish('x', 'y');
        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingPublished();
    }

    public function testAssertPublishedCount(): void
    {
        $fake = new FakeAmqp();
        $fake->publish('a', 'x');
        $fake->publish('a', 'y');
        $fake->publish('b', 'z');
        $fake->assertPublishedCount(3);
        $fake->assertPublishedCount(2, 'a');
        $fake->assertPublishedCount(1, 'b');
        $this->assertCount(3, $fake->published());
    }

    public function testClearResetsRecords(): void
    {
        $fake = new FakeAmqp();
        $fake->publish('x', 'y');
        $fake->clear();
        $fake->assertNothingPublished();
        $this->assertSame([], $fake->published());
    }
}
