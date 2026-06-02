<?php

namespace Bschmitt\Amqp\Test\Support\Fixtures\Rpc;

use Bschmitt\Amqp\Rpc\RpcService;

class UserService extends RpcService
{
    public static function queue(): string
    {
        return 'rpc.user-service';
    }

    public static function methods(): array
    {
        return [
            GetUserRequest::class => 'getUser',
            CreateUserRequest::class => 'createUser',
        ];
    }
}
