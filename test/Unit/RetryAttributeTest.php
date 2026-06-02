<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Attributes\Retry;
use Bschmitt\Amqp\Support\RetryPolicy;
use Bschmitt\Amqp\Support\RetryStrategy;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use InvalidArgumentException;

class RetryAttributeTest extends BaseTestCase
{
    public function testAttributeStoresFields(): void
    {
        $retry = new Retry(5, RetryStrategy::EXPONENTIAL, 200, 30000, true);
        $this->assertSame(5, $retry->attempts);
        $this->assertSame('exponential', $retry->strategy);
        $this->assertSame(200, $retry->delayMs);
        $this->assertSame(30000, $retry->maxDelayMs);
        $this->assertTrue($retry->jitter);

        $expected = [
            'attempts' => 5,
            'strategy' => 'exponential',
            'delayMs' => 200,
            'maxDelayMs' => 30000,
            'jitter' => true,
        ];
        $this->assertSame($expected, $retry->toArray());
    }

    public function testAttributeRejectsInvalidStrategy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Retry(3, 'banana');
    }

    public function testAttributeRejectsZeroAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Retry(0);
    }

    public function testRetryStrategyValuesAndValidation(): void
    {
        $this->assertContains('exponential', RetryStrategy::values());
        $this->assertTrue(RetryStrategy::isValid('fixed'));
        $this->assertFalse(RetryStrategy::isValid('nope'));
    }

    public function testFromAttributeReturnsNoneWhenAbsent(): void
    {
        $policy = RetryPolicy::fromAttribute(self::class);
        $this->assertSame(0, $policy->maxAttempts());
    }

    public function testFromAttributeBuildsPolicyOnPhp8Plus(): void
    {
        if (PHP_VERSION_ID < 80000) {
            $this->markTestSkipped('Attributes require PHP 8.0+');
        }

        $policy = RetryPolicy::fromAttribute(RetryAttributeFixture::class, 'handle');

        // attempts: 4 -> maxAttempts = 3 (1 initial + 3 retries)
        $this->assertSame(3, $policy->maxAttempts());
        $this->assertSame('exponential', $policy->strategy());
        $this->assertSame(250, $policy->baseDelayMs());
    }
}

// PHP 8+ attribute application; the file still parses on 7.x because the
// `#[...]` line above the method is treated as a comment.
class RetryAttributeFixture
{
    #[\Bschmitt\Amqp\Attributes\Retry(attempts: 4, strategy: 'exponential', delayMs: 250)]
    public function handle(): void
    {
    }
}
