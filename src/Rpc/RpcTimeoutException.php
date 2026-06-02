<?php

namespace Bschmitt\Amqp\Rpc;

/**
 * Thrown when an RPC call did not receive a reply within the configured timeout.
 */
class RpcTimeoutException extends \RuntimeException
{
}
