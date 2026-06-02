<?php

namespace Bschmitt\Amqp\Support;

/**
 * Outcome of an {@see RpcClient} call.
 *
 * Carries enough metadata for observability — duration, timeout flag, and an
 * optional error class — so callers can branch on `succeeded()`, log
 * `durationMs()` to Pulse/Prometheus, and surface `errorClass()` in traces.
 */
class RpcCallResult
{
    /** @var mixed */
    protected $body;

    /** @var bool */
    protected $timedOut;

    /** @var string|null */
    protected $correlationId;

    /** @var float|null */
    protected $durationMs;

    /** @var string|null */
    protected $errorClass;

    /**
     * @param mixed       $body          Raw response body (null on timeout).
     * @param bool        $timedOut
     * @param string|null $correlationId
     * @param float|null  $durationMs    Round-trip duration in milliseconds.
     * @param string|null $errorClass    FQCN of the remote exception (if any).
     */
    public function __construct(
        $body,
        bool $timedOut,
        ?string $correlationId = null,
        ?float $durationMs = null,
        ?string $errorClass = null
    ) {
        $this->body = $body;
        $this->timedOut = $timedOut;
        $this->correlationId = $correlationId;
        $this->durationMs = $durationMs;
        $this->errorClass = $errorClass;
    }

    public function succeeded(): bool
    {
        return !$this->timedOut && $this->body !== null && $this->errorClass === null;
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    public function failed(): bool
    {
        return $this->timedOut || $this->errorClass !== null;
    }

    /**
     * @return mixed
     */
    public function body()
    {
        return $this->body;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * Round-trip duration in milliseconds. `null` when the call wasn't timed
     * (e.g. user constructed the result directly).
     */
    public function durationMs(): ?float
    {
        return $this->durationMs;
    }

    public function errorClass(): ?string
    {
        return $this->errorClass;
    }
}
