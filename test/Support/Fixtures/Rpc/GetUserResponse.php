<?php

namespace Bschmitt\Amqp\Test\Support\Fixtures\Rpc;

use Bschmitt\Amqp\Rpc\RpcResponse;

class GetUserResponse extends RpcResponse
{
    /** @var int|null */
    public $id;

    /** @var string|null */
    public $name;

    public function __construct($id = null, $name = null)
    {
        $this->id = $id;
        $this->name = $name;
    }
}
