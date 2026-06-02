<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Events\MessagePublishing;
use Bschmitt\Amqp\Support\EventDispatcher;
use Bschmitt\Amqp\Test\Support\BaseTestCase;

class EventDispatcherTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        EventDispatcher::instance()->flushListeners();
        parent::tearDown();
    }

    public function testListenersReceiveDispatchedEvents(): void
    {
        $received = null;
        EventDispatcher::instance()->listen(MessagePublishing::class, function ($event) use (&$received) {
            $received = $event;
        });

        $event = new MessagePublishing('rk', 'body', ['exchange' => 'app']);
        EventDispatcher::instance()->dispatch($event);

        $this->assertSame($event, $received);
    }

    public function testFlushListenersRemovesAll(): void
    {
        $called = false;
        EventDispatcher::instance()->listen(MessagePublishing::class, function () use (&$called) {
            $called = true;
        });
        EventDispatcher::instance()->flushListeners();

        EventDispatcher::instance()->dispatch(new MessagePublishing('rk', 'body', []));
        $this->assertFalse($called);
    }
}
