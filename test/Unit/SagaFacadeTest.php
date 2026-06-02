<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Facades\Saga;
use Bschmitt\Amqp\Support\Saga as SagaWorkflow;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use LogicException;
use RuntimeException;

class SagaFacadeTest extends BaseTestCase
{
    public function testFluentMakeAndCompensateRunsSuccessfully(): void
    {
        $log = [];

        $result = Saga::make('checkout')
            ->step('reserve', function () use (&$log) {
                $log[] = 'reserve';
                return 'stock-1';
            })->compensate(function () use (&$log) {
                $log[] = 'release';
            })
            ->step('charge', function () use (&$log) {
                $log[] = 'charge';
                return 'tx-1';
            })->compensate(function () use (&$log) {
                $log[] = 'refund';
            })
            ->execute([]);

        $this->assertTrue($result->succeeded());
        $this->assertSame(['reserve', 'charge'], $log);
    }

    public function testCompensationRunsInReverseOnFailure(): void
    {
        $log = [];

        $result = SagaWorkflow::make('checkout')
            ->step('reserve', function () use (&$log) {
                $log[] = 'reserve';
                return 'stock-1';
            })->compensate(function () use (&$log) {
                $log[] = 'release';
            })
            ->step('charge', function () use (&$log) {
                $log[] = 'charge';
                throw new RuntimeException('payment declined');
            })
            ->execute([]);

        $this->assertFalse($result->succeeded());
        $this->assertSame('charge', $result->getFailedStep());
        $this->assertSame(['reserve', 'charge', 'release'], $log);
        $this->assertSame(['reserve'], $result->getCompensatedSteps());
    }

    public function testCompensateWithoutStepThrows(): void
    {
        $this->expectException(LogicException::class);
        Saga::make()->compensate(function () {});
    }

    public function testMakeFactoryAcceptsCustomName(): void
    {
        $saga = SagaWorkflow::make('my-checkout');
        $this->assertSame('my-checkout', $saga->getName());
    }
}
