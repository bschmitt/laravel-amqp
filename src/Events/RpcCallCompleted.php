<?php

namespace Bschmitt\Amqp\Events;

/**
 * Fired by {@see \Bschmitt\Amqp\Rpc\RpcDispatcher::call()} after a successful
 * RPC round-trip. The `durationMs` value is the full client-observed latency
 * (publish + broker + handler + reply).
 */
class RpcCallCompleted
{
    /** @var string */
    public $service;

    /** @var string */
    public $request;

    /** @var float */
    public $durationMs;

    /** @var string|null */
    public $correlationId;

    /**
     * @param string      $service
     * @param string      $request
     * @param float       $durationMs
     * @param string|null $correlationId
     */
    public function __construct(string $service, string $request, float $durationMs, ?string $correlationId = null)
    {
        $this->service = $service;
        $this->request = $request;
        $this->durationMs = $durationMs;
        $this->correlationId = $correlationId;
    }
}
