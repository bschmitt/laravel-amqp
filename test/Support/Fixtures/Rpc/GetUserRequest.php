<?php

namespace Bschmitt\Amqp\Test\Support\Fixtures\Rpc;

use Bschmitt\Amqp\Rpc\RpcRequest;

class GetUserRequest extends RpcRequest
{
    /** @var int|null */
    public $id;

    public function __construct($id = null)
    {
        $this->id = $id;
    }

    public static function responseClass()
    {
        return GetUserResponse::class;
    }
}
