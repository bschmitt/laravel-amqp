<?php

namespace Bschmitt\Amqp\Test\Support\Fixtures\Rpc;

use Bschmitt\Amqp\Rpc\RpcRequest;

class CreateUserRequest extends RpcRequest
{
    /** @var string|null */
    public $name;

    public function __construct($name = null)
    {
        $this->name = $name;
    }

    public static function responseClass()
    {
        return GetUserResponse::class;
    }
}
