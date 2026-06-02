<?php

namespace Bschmitt\Amqp\Events;

/**
 * Fired by {@see \Bschmitt\Amqp\Rpc\RpcDispatcher::call()} before issuing
 * the AMQP RPC publish. Useful for correlating logs / traces.
 */
class RpcCallStarted
{
    /** @var string */
    public $service;

    /** @var string */
    public $request;

    /** @var string|null */
    public $correlationId;

    /**
     * @param string      $service       Service FQCN.
     * @param string      $request       Request DTO FQCN.
     * @param string|null $correlationId
     */
    public function __construct(string $service, string $request, ?string $correlationId = null)
    {
        $this->service = $service;
        $this->request = $request;
        $this->correlationId = $correlationId;
    }
}
