<?php

namespace Bschmitt\Amqp\Support;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Hooks and graceful shutdown for long-running AMQP consumers.
 *
 * Register signal handlers (when ext-pcntl is available), run callbacks
 * before/after each message, and cooperatively stop the consume loop via
 * {@see requestStop()}.
 */
class ConsumerLifecycle
{
    /** @var array<int, callable> */
    protected $startingHooks = [];

    /** @var array<int, callable> */
    protected $stoppingHooks = [];

    /** @var array<int, callable> */
    protected $messageHooks = [];

    /** @var array<int, callable> */
    protected $errorHooks = [];

    /** @var bool */
    protected $shouldStop = false;

    /** @var bool */
    protected $signalsRegistered = false;

    /** @var HealthState|null */
    protected $health;

    /**
     * Attach a {@see HealthState} that should mirror this consumer's state.
     * When set, the lifecycle marks the worker ready on start, dead on
     * shutdown, and stamps a heartbeat on every message — making the
     * existing hooks Kubernetes-probe ready.
     *
     * @return $this
     */
    public function withHealth(HealthState $state): self
    {
        $this->health = $state;
        return $this;
    }

    public function health(): ?HealthState
    {
        return $this->health;
    }

    /**
     * @param callable $callback function (ConsumerLifecycle $lifecycle): void
     * @return $this
     */
    public function onStarting(callable $callback): self
    {
        $this->startingHooks[] = $callback;

        return $this;
    }

    /**
     * @param callable $callback function (ConsumerLifecycle $lifecycle): void
     * @return $this
     */
    public function onStopping(callable $callback): self
    {
        $this->stoppingHooks[] = $callback;

        return $this;
    }

    /**
     * @param callable $callback function (AMQPMessage $message, ConsumerLifecycle $lifecycle): void
     * @return $this
     */
    public function onMessage(callable $callback): self
    {
        $this->messageHooks[] = $callback;

        return $this;
    }

    /**
     * @param callable $callback function (\Throwable $e, AMQPMessage|null $message, ConsumerLifecycle $lifecycle): void
     * @return $this
     */
    public function onError(callable $callback): self
    {
        $this->errorHooks[] = $callback;

        return $this;
    }

    /**
     * @return void
     */
    public function requestStop(): void
    {
        $this->shouldStop = true;
        if ($this->health !== null) {
            $this->health->markNotReady('stop requested');
        }
    }

    /**
     * @return bool
     */
    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    /**
     * Register SIGINT/SIGTERM handlers when pcntl is available.
     *
     * @return $this
     */
    public function registerSignalHandlers(): self
    {
        if ($this->signalsRegistered || !function_exists('pcntl_signal')) {
            return $this;
        }

        $handler = function () {
            $this->requestStop();
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
        $this->signalsRegistered = true;

        return $this;
    }

    /**
     * Dispatch pcntl signals if supported.
     *
     * @return void
     */
    public function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * Run starting hooks once.
     *
     * @return void
     */
    public function fireStarting(): void
    {
        if ($this->health !== null) {
            $this->health->markStarting();
        }
        foreach ($this->startingHooks as $hook) {
            call_user_func($hook, $this);
        }
        if ($this->health !== null) {
            $this->health->markReady('consumer started');
        }
    }

    /**
     * Run stopping hooks once.
     *
     * @return void
     */
    public function fireStopping(): void
    {
        if ($this->health !== null) {
            $this->health->markDead('consumer stopped');
        }
        foreach ($this->stoppingHooks as $hook) {
            call_user_func($hook, $this);
        }
    }

    /**
     * @param AMQPMessage $message
     * @return void
     */
    public function fireMessage(AMQPMessage $message): void
    {
        if ($this->health !== null) {
            $this->health->heartbeat();
            $this->health->recordProcessed();
        }
        foreach ($this->messageHooks as $hook) {
            call_user_func($hook, $message, $this);
        }
    }

    /**
     * @param \Throwable       $exception
     * @param AMQPMessage|null $message
     * @return void
     */
    public function fireError(\Throwable $exception, ?AMQPMessage $message = null): void
    {
        if ($this->health !== null) {
            $this->health->recordError();
        }
        foreach ($this->errorHooks as $hook) {
            call_user_func($hook, $exception, $message, $this);
        }
    }

    /**
     * Wrap a consume callback with lifecycle hooks and error handling.
     *
     * @param callable $handler function (AMQPMessage $message): mixed
     * @return callable
     */
    public function wrap(callable $handler): callable
    {
        $lifecycle = $this;

        return function (AMQPMessage $message) use ($handler, $lifecycle) {
            if ($lifecycle->shouldStop()) {
                return;
            }

            $lifecycle->dispatchSignals();

            try {
                $lifecycle->fireMessage($message);
                return call_user_func($handler, $message);
            } catch (\Throwable $e) {
                $lifecycle->fireError($e, $message);
                throw $e;
            }
        };
    }
}
