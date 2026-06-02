<?php

namespace Bschmitt\Amqp\Facades;

use Bschmitt\Amqp\Support\Saga as SagaWorkflow;

/**
 * Facade-style shortcut for {@see SagaWorkflow}.
 *
 *   Saga::make('checkout')
 *     ->step('reserve', $reserve)->compensate($release)
 *     ->step('charge',  $charge)->compensate($refund)
 *     ->execute(['orderId' => 1]);
 *
 * Implemented as a plain class rather than `Illuminate\Support\Facades\Facade`
 * because the saga itself is stateless and per-workflow.
 */
class Saga
{
    /**
     * @param string $name
     * @return SagaWorkflow
     */
    public static function make(string $name = 'saga'): SagaWorkflow
    {
        return SagaWorkflow::make($name);
    }

    /**
     * Forward static calls to a fresh saga instance.
     *
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    public static function __callStatic(string $method, array $args)
    {
        $saga = SagaWorkflow::make();

        return $saga->{$method}(...$args);
    }
}
