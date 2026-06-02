<?php

namespace Bschmitt\Amqp\Events;

/**
 * Fired by {@see \Bschmitt\Amqp\Rpc\RpcDispatcher::call()} on timeout or
 * remote handler error. `errorClass` is `null` for timeouts.
 */
class RpcCallFailed
{
    /** @var string */
    public $service;

    /** @var string */
    public $request;

    /** @var float */
    public $durationMs;

    /** @var bool */
    public $timedOut;

    /** @var string|null */
    public $errorClass;

    /** @var string|null */
    public $errorMessage;

    /** @var string|null */
    public $correlationId;

    /**
     * @param string      $service
     * @param string      $request
     * @param float       $durationMs
     * @param bool        $timedOut
     * @param string|null $errorClass
     * @param string|null $errorMessage
     * @param string|null $correlationId
     */
    public function __construct(
        string $service,
        string $request,
        float $durationMs,
        bool $timedOut,
        ?string $errorClass = null,
        ?string $errorMessage = null,
        ?string $correlationId = null
    ) {
        $this->service = $service;
        $this->request = $request;
        $this->durationMs = $durationMs;
        $this->timedOut = $timedOut;
        $this->errorClass = $errorClass;
        $this->errorMessage = $errorMessage;
        $this->correlationId = $correlationId;
    }
}
