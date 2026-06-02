<?php

namespace Bschmitt\Amqp\Test\Unit\Rpc;

use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Bschmitt\Amqp\Test\Support\Fixtures\Rpc\GetUserRequest;
use Bschmitt\Amqp\Test\Support\Fixtures\Rpc\GetUserResponse;

class RpcMessageTest extends BaseTestCase
{
    public function testMakeHydratesPublicProperties(): void
    {
        $request = GetUserRequest::make(['id' => 7]);
        $this->assertSame(7, $request->id);
        $this->assertSame(['id' => 7], $request->toPayload());
    }

    public function testMakeIgnoresUnknownKeys(): void
    {
        $request = GetUserRequest::make(['id' => 7, 'unknown' => 'x']);
        $this->assertSame(7, $request->id);
    }

    public function testResponseSerializesRoundtrip(): void
    {
        $response = GetUserResponse::make(['id' => 1, 'name' => 'A']);
        $copy = GetUserResponse::fromPayload($response->toPayload());
        $this->assertEquals($response, $copy);
    }
}
