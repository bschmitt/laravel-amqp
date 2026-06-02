<?php

namespace Bschmitt\Amqp\Support;

/**
 * Tiny wrapper that dispatches package events via the Laravel event
 * dispatcher when it is available (and no-ops outside Laravel context).
 *
 * Listeners outside Laravel can still register through {@see listen()}.
 */
class EventDispatcher
{
    /** @var self|null */
    protected static $instance;

    /** @var array<string, array<int, callable>> */
    protected $listeners = [];

    /**
     * @return self
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Replace the singleton (primarily for tests).
     *
     * @param self|null $dispatcher
     * @return void
     */
    public static function setInstance(?self $dispatcher): void
    {
        self::$instance = $dispatcher;
    }

    /**
     * @param string   $eventClass
     * @param callable $listener function ($event): void
     * @return void
     */
    public function listen(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * Dispatch through Laravel's event dispatcher (when available) and any
     * locally registered listeners.
     *
     * @param object $event
     * @return void
     */
    public function dispatch($event): void
    {
        $this->dispatchThroughLaravel($event);

        $eventClass = get_class($event);
        if (!isset($this->listeners[$eventClass])) {
            return;
        }
        foreach ($this->listeners[$eventClass] as $listener) {
            call_user_func($listener, $event);
        }
    }

    /**
     * @return void
     */
    public function flushListeners(): void
    {
        $this->listeners = [];
    }

    /**
     * @param object $event
     * @return void
     */
    protected function dispatchThroughLaravel($event): void
    {
        if (!class_exists(\Illuminate\Support\Facades\Event::class)) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Event::dispatch($event);
        } catch (\Throwable $e) {
            // Outside a Laravel application, the facade resolves to null;
            // swallow so the publish/consume flow is unaffected.
        }
    }
}
