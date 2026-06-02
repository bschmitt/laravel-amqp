<?php

namespace Bschmitt\Amqp\Rpc;

/**
 * Fluent caller returned by `Rpc::service('payments')`.
 *
 * Carries the resolved service class so callers don't repeat themselves:
 *
 *   Rpc::service('payments')
 *       ->call(GetPaymentRequest::make(['id' => 1]));
 *
 *   Rpc::service('payments')->timeout(5)->call($req);
 */
class ServiceCaller
{
    /** @var RpcDispatcher */
    protected $dispatcher;

    /** @var class-string<RpcService> */
    protected $serviceClass;

    /** @var int|null */
    protected $timeoutSeconds;

    /** @var array<string, mixed> */
    protected $properties = [];

    /**
     * @param RpcDispatcher            $dispatcher
     * @param class-string<RpcService> $serviceClass
     */
    public function __construct(RpcDispatcher $dispatcher, string $serviceClass)
    {
        $this->dispatcher = $dispatcher;
        $this->serviceClass = $serviceClass;
    }

    /**
     * @param int $seconds
     * @return $this
     */
    public function timeout(int $seconds): self
    {
        $this->timeoutSeconds = max(1, $seconds);

        return $this;
    }

    /**
     * Merge additional publish properties.
     *
     * @param array<string, mixed> $properties
     * @return $this
     */
    public function withProperties(array $properties): self
    {
        $this->properties = array_merge($this->properties, $properties);

        return $this;
    }

    /**
     * @param RpcRequest $request
     * @return RpcResponse|array<string, mixed>
     */
    public function call(RpcRequest $request)
    {
        return $this->dispatcher->call(
            $this->serviceClass,
            $request,
            $this->timeoutSeconds,
            $this->properties
        );
    }

    /**
     * @return class-string<RpcService>
     */
    public function serviceClass(): string
    {
        return $this->serviceClass;
    }
}
