<?php

namespace Bschmitt\Amqp\Rpc;

/**
 * Thrown when the remote handler responded with an error envelope.
 *
 * The wire format is `{"_rpc_error": "message", "_rpc_class": "FQCN|null"}`.
 */
class RpcException extends \RuntimeException
{
    /** @var string|null */
    protected $remoteClass;

    /**
     * @param string      $message
     * @param string|null $remoteClass
     */
    public function __construct(string $message, ?string $remoteClass = null)
    {
        parent::__construct($message);
        $this->remoteClass = $remoteClass;
    }

    public function remoteClass(): ?string
    {
        return $this->remoteClass;
    }
}
