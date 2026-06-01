<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\ConsumerLifecycle;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumerLifecycleTest extends BaseTestCase
{
    public function testWrapInvokesMessageHookBeforeHandler(): void
    {
        $order = [];
        $lifecycle = new ConsumerLifecycle();
        $lifecycle->onMessage(function () use (&$order) {
            $order[] = 'before';
        });

        $wrapped = $lifecycle->wrap(function () use (&$order) {
            $order[] = 'handler';
        });

        $wrapped(new AMQPMessage('x'));
        $this->assertSame(['before', 'handler'], $order);
    }

    public function testRequestStopSkipsHandler(): void
    {
        $called = false;
        $lifecycle = new ConsumerLifecycle();
        $lifecycle->requestStop();
        $wrapped = $lifecycle->wrap(function () use (&$called) {
            $called = true;
        });

        $wrapped(new AMQPMessage('x'));
        $this->assertFalse($called);
    }

    public function testErrorHookRunsOnException(): void
    {
        $caught = null;
        $lifecycle = new ConsumerLifecycle();
        $lifecycle->onError(function ($e) use (&$caught) {
            $caught = $e;
        });

        $wrapped = $lifecycle->wrap(function () {
            throw new \RuntimeException('boom');
        });

        try {
            $wrapped(new AMQPMessage('x'));
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertInstanceOf(\RuntimeException::class, $caught);
    }
}
