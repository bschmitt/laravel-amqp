<?php

namespace Bschmitt\Amqp\Test\Support\Fixtures\Rpc;

class UserServiceHandler
{
    public function getUser(GetUserRequest $request): GetUserResponse
    {
        return GetUserResponse::make([
            'id' => $request->id,
            'name' => 'User #'.$request->id,
        ]);
    }

    public function createUser(CreateUserRequest $request): GetUserResponse
    {
        return GetUserResponse::make([
            'id' => 99,
            'name' => $request->name,
        ]);
    }
}
