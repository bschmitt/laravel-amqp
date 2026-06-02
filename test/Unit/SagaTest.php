<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\Saga;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use InvalidArgumentException;

class SagaTest extends BaseTestCase
{
    public function testRunsStepsInOrderOnSuccess(): void
    {
        $order = [];
        $saga = (new Saga('checkout'))
            ->step('reserve', function () use (&$order) {
                $order[] = 'reserve';
                return 'reserved-1';
            })
            ->step('charge', function () use (&$order) {
                $order[] = 'charge';
                return 'charged-1';
            });

        $result = $saga->execute(['orderId' => 1]);
        $this->assertTrue($result->succeeded());
        $this->assertSame(['reserve', 'charge'], $order);
        $this->assertSame('reserved-1', $result->getStepResults()['reserve']);
    }

    public function testCompensatesCompletedStepsInReverseOnFailure(): void
    {
        $order = [];
        $saga = (new Saga())
            ->step('reserve', function () use (&$order) {
                $order[] = 'reserve';
            }, function () use (&$order) {
                $order[] = 'compensate-reserve';
            })
            ->step('charge', function () use (&$order) {
                $order[] = 'charge';
            }, function () use (&$order) {
                $order[] = 'compensate-charge';
            })
            ->step('ship', function () {
                throw new \RuntimeException('out of stock');
            });

        $result = $saga->execute();
        $this->assertTrue($result->failed());
        $this->assertSame('ship', $result->getFailedStep());
        $this->assertSame('out of stock', $result->getException()->getMessage());
        $this->assertSame(
            ['reserve', 'charge', 'compensate-charge', 'compensate-reserve'],
            $order
        );
        $this->assertSame(['charge', 'reserve'], $result->getCompensatedSteps());
    }

    public function testEmptyStepNameRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Saga())->step('', function () {});
    }
}
