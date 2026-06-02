<?php

namespace Bschmitt\Amqp\Test\Unit\Rpc;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Rpc\RpcDispatcher;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Bschmitt\Amqp\Test\Support\Fixtures\Rpc\GetUserRequest;
use Bschmitt\Amqp\Test\Support\Fixtures\Rpc\GetUserResponse;
use Bschmitt\Amqp\Test\Support\Fixtures\Rpc\UserService;
use InvalidArgumentException;
use Mockery as m;

class ServiceCallerTest extends BaseTestCase
{
    public function testServiceByAliasReturnsCallerForRegisteredService(): void
    {
        $amqp = m::mock(Amqp::class);
        $dispatcher = new RpcDispatcher($amqp);
        $dispatcher->services()->register('users', UserService::class);

        $caller = $dispatcher->service('users');
        $this->assertSame(UserService::class, $caller->serviceClass());
    }

    public function testServiceAcceptsFqcnDirectly(): void
    {
        $dispatcher = new RpcDispatcher(m::mock(Amqp::class));
        $caller = $dispatcher->service(UserService::class);
        $this->assertSame(UserService::class, $caller->serviceClass());
    }

    public function testServiceUnknownAliasThrows(): void
    {
        $dispatcher = new RpcDispatcher(m::mock(Amqp::class));
        $this->expectException(InvalidArgumentException::class);
        $dispatcher->service('nope');
    }

    public function testCallForwardsToDispatcherAndHydratesResponse(): void
    {
        $amqp = m::mock(Amqp::class);
        $amqp->shouldReceive('rpc')
            ->once()
            ->andReturnUsing(function ($routing, $body, $properties, $timeout) {
                $this->assertSame('rpc.user-service', $routing);
                $this->assertSame(5, $timeout);
                return json_encode(['id' => 7, 'name' => 'Grace']);
            });

        $dispatcher = new RpcDispatcher($amqp);
        $dispatcher->services()->register('users', UserService::class);

        $response = $dispatcher->service('users')
            ->timeout(5)
            ->withProperties(['exchange' => 'rpc.users'])
            ->call(GetUserRequest::make(['id' => 7]));

        $this->assertInstanceOf(GetUserResponse::class, $response);
        $this->assertSame(7, $response->id);
        $this->assertSame('Grace', $response->name);
    }
}
