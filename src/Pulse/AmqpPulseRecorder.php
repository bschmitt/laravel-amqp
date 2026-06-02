<?php

namespace Bschmitt\Amqp\Pulse;

use Bschmitt\Amqp\Events\DeadLetterDetected;
use Bschmitt\Amqp\Events\MessageFailed;
use Bschmitt\Amqp\Events\MessageHandled;
use Bschmitt\Amqp\Events\MessagePublished;
use Bschmitt\Amqp\Events\RpcCallCompleted;
use Bschmitt\Amqp\Events\RpcCallFailed;

/**
 * Bridges the package's Laravel events to {@see \Laravel\Pulse\Facades\Pulse}.
 *
 * Pulse is **optional** — if `laravel/pulse` is not installed (or not booted
 * yet) every record call is silently dropped. That lets the recorder live in
 * `boot()` of the service provider without imposing a dependency on Pulse.
 *
 * The recorder emits the following metric "types" (consumable from custom
 * Pulse cards or via `Pulse::values()`):
 *
 *   | Type             | Key                              | Value             |
 *   |------------------|----------------------------------|-------------------|
 *   | `amqp_publish`   | routing key                      | 1 (count)         |
 *   | `amqp_handle`    | queue                            | duration (ms)     |
 *   | `amqp_fail`      | queue                            | 1 (count)         |
 *   | `amqp_rpc`       | service::request                 | duration (ms)     |
 *   | `amqp_rpc_fail`  | service::request                 | 1 (count)         |
 *   | `amqp_dlq`       | dlq queue                        | sampled msg count |
 */
class AmqpPulseRecorder
{
    /** @var bool|null Cached check for Pulse availability. */
    protected $pulseAvailable;

    /** @var callable|null Test-only override: receives ($type, $key, $value). */
    protected $recordHook;

    /**
     * Replace the default Pulse facade call with a custom recorder. The hook
     * receives `(string $type, string $key, int $value)` for every metric.
     *
     * Primary use case: unit tests. Production code should leave the hook
     * unset and let the recorder discover the Pulse facade via class_exists.
     *
     * @param callable|null $hook
     * @return void
     */
    public function setRecordHook(?callable $hook): void
    {
        $this->recordHook = $hook;
        $this->pulseAvailable = null;
    }

    /**
     * @param MessagePublished $event
     * @return void
     */
    public function recordPublished(MessagePublished $event): void
    {
        $this->record('amqp_publish', $event->routing, 1);
    }

    /**
     * @param MessageHandled $event
     * @return void
     */
    public function recordHandled(MessageHandled $event): void
    {
        $this->record('amqp_handle', $event->queue, (int) round($event->durationMs));
    }

    /**
     * @param MessageFailed $event
     * @return void
     */
    public function recordFailed(MessageFailed $event): void
    {
        $this->record('amqp_fail', $event->queue, 1);
    }

    /**
     * @param RpcCallCompleted $event
     * @return void
     */
    public function recordRpcCompleted(RpcCallCompleted $event): void
    {
        $key = $this->shortClass($event->service) . '::' . $this->shortClass($event->request);
        $this->record('amqp_rpc', $key, (int) round($event->durationMs));
    }

    /**
     * @param RpcCallFailed $event
     * @return void
     */
    public function recordRpcFailed(RpcCallFailed $event): void
    {
        $key = $this->shortClass($event->service) . '::' . $this->shortClass($event->request);
        $this->record('amqp_rpc_fail', $key, 1);
    }

    /**
     * @param DeadLetterDetected $event
     * @return void
     */
    public function recordDeadLetter(DeadLetterDetected $event): void
    {
        $this->record('amqp_dlq', $event->queue, max(1, $event->messageCount));
    }

    /**
     * @return bool
     */
    public function isPulseAvailable(): bool
    {
        if ($this->recordHook !== null) {
            return true;
        }
        if ($this->pulseAvailable === null) {
            $this->pulseAvailable = class_exists('\\Laravel\\Pulse\\Facades\\Pulse');
        }

        return $this->pulseAvailable;
    }

    /**
     * @param string $type
     * @param string $key
     * @param int    $value
     * @return void
     */
    protected function record(string $type, string $key, int $value): void
    {
        $normalizedKey = $key !== '' ? $key : '_default';

        if ($this->recordHook !== null) {
            call_user_func($this->recordHook, $type, $normalizedKey, $value);
            return;
        }

        if (!$this->isPulseAvailable()) {
            return;
        }

        $pulse = '\\Laravel\\Pulse\\Facades\\Pulse';

        try {
            // Pulse::record($type, $key, $value)
            call_user_func([$pulse, 'record'], $type, $normalizedKey, $value);
        } catch (\Throwable $e) {
            // Pulse facade not yet bound — swallow so consume/publish keeps
            // running. We don't want metrics to interfere with messaging.
        }
    }

    /**
     * @param string $class
     * @return string
     */
    protected function shortClass(string $class): string
    {
        if ($class === '') {
            return '_anonymous';
        }
        $parts = explode('\\', $class);

        return (string) end($parts);
    }
}
