<?php

namespace Bschmitt\Amqp\Support;

/**
 * Outcome of an {@see RpcClient} call.
 */
class RpcCallResult
{
    /** @var mixed */
    protected $body;

    /** @var bool */
    protected $timedOut;

    /** @var string|null */
    protected $correlationId;

    /**
     * @param mixed       $body Raw response body (null on timeout).
     * @param bool        $timedOut
     * @param string|null $correlationId
     */
    public function __construct($body, bool $timedOut, ?string $correlationId = null)
    {
        $this->body = $body;
        $this->timedOut = $timedOut;
        $this->correlationId = $correlationId;
    }

    public function succeeded(): bool
    {
        return !$this->timedOut && $this->body !== null;
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
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
}
