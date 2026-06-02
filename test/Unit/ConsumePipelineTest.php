<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Contracts\ConsumeMiddlewareInterface;
use Bschmitt\Amqp\Support\ConsumePipeline;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumePipelineTest extends BaseTestCase
{
    public function testMiddlewaresRunInRegistrationOrder(): void
    {
        $order = [];
        $pipeline = (new ConsumePipeline())
            ->push(function ($message, $next) use (&$order) {
                $order[] = 'a-before';
                $next($message);
                $order[] = 'a-after';
            })
            ->push(function ($message, $next) use (&$order) {
                $order[] = 'b-before';
                $next($message);
                $order[] = 'b-after';
            });

        $wrapped = $pipeline->wrap(function () use (&$order) {
            $order[] = 'handler';
        });

        $wrapped(new AMQPMessage('x'), null);
        $this->assertSame(
            ['a-before', 'b-before', 'handler', 'b-after', 'a-after'],
            $order
        );
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $called = false;
        $pipeline = (new ConsumePipeline())
            ->push(function () {
                // do not call $next
            });

        $wrapped = $pipeline->wrap(function () use (&$called) {
            $called = true;
        });
        $wrapped(new AMQPMessage('x'), null);
        $this->assertFalse($called);
    }

    public function testInterfaceMiddlewareWorks(): void
    {
        $order = [];
        $middleware = new class ($order) implements ConsumeMiddlewareInterface {
            public $order;
            public function __construct(array &$order)
            {
                $this->order = &$order;
            }
            public function handle(AMQPMessage $message, callable $next)
            {
                $this->order[] = 'iface-before';
                $next($message);
                $this->order[] = 'iface-after';
            }
        };

        $pipeline = (new ConsumePipeline())->push($middleware);
        $wrapped = $pipeline->wrap(function () use (&$order) {
            $order[] = 'handler';
        });

        $wrapped(new AMQPMessage('x'), null);
        $this->assertSame(['iface-before', 'handler', 'iface-after'], $middleware->order);
    }
}
