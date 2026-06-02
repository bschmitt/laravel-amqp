<?php

namespace Bschmitt\Amqp\Test\Unit\Rpc;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Rpc\RpcDispatcher;
use Bschmitt\Amqp\Rpc\RpcException;
use Bschmitt\Amqp\Rpc\RpcTimeoutException;
use Bschmitt\Amqp\Support\RpcLatencyRecorder;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Bschmitt\Amqp\Test\Support\Fixtures\Rpc\CreateUserRequest;
use Bschmitt\Amqp\Test\Support\Fixtures\Rpc\GetUserRequest;
use Bschmitt\Amqp\Test\Support\Fixtures\Rpc\GetUserResponse;
use Bschmitt\Amqp\Test\Support\Fixtures\Rpc\UserService;
use Bschmitt\Amqp\Test\Support\Fixtures\Rpc\UserServiceHandler;
use InvalidArgumentException;
use Mockery as m;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

class RpcDispatcherTest extends BaseTestCase
{
    /**
     * Build a mocked Amqp with a real RpcLatencyRecorder so tests can assert
     * call counts and durations without stubbing every recorder method.
     *
     * @return array{0: \Mockery\MockInterface, 1: RpcLatencyRecorder}
     */
    protected function makeAmqp(): array
    {
        $amqp = m::mock(Amqp::class);
        $recorder = new RpcLatencyRecorder();
        $amqp->shouldReceive('rpcMetrics')->andReturn($recorder);

        return [$amqp, $recorder];
    }

    public function testCallSerializesRequestAndHydratesResponse(): void
    {
        [$amqp, $recorder] = $this->makeAmqp();

        $capturedRouting = null;
        $capturedBody = null;
        $capturedProperties = null;

        $amqp->shouldReceive('rpc')
            ->once()
            ->andReturnUsing(function ($routing, $body, $properties, $timeout) use (
                &$capturedRouting,
                &$capturedBody,
                &$capturedProperties
            ) {
                $capturedRouting = $routing;
                $capturedBody = $body;
                $capturedProperties = $properties;
                return json_encode(['id' => 5, 'name' => 'Ada']);
            });

        $dispatcher = new RpcDispatcher($amqp);
        $response = $dispatcher->call(UserService::class, GetUserRequest::make(['id' => 5]));

        $this->assertSame('rpc.user-service', $capturedRouting);
        $this->assertSame(['id' => 5], json_decode($capturedBody, true));
        $this->assertSame(UserService::class, $capturedProperties['application_headers']['x-rpc-service']);
        $this->assertSame(GetUserRequest::class, $capturedProperties['application_headers']['x-rpc-request']);
        $this->assertSame('application/json', $capturedProperties['content_type']);

        $this->assertInstanceOf(GetUserResponse::class, $response);
        $this->assertSame(5, $response->id);
        $this->assertSame('Ada', $response->name);

        $stats = $recorder->for('UserService::GetUserRequest');
        $this->assertNotNull($stats);
        $this->assertSame(1, $stats['count']);
        $this->assertSame(0, $stats['failed']);
    }

    public function testCallThrowsOnTimeoutAndRecordsFailure(): void
    {
        [$amqp, $recorder] = $this->makeAmqp();
        $amqp->shouldReceive('rpc')->once()->andReturn(null);

        $dispatcher = new RpcDispatcher($amqp);

        try {
            $dispatcher->call(UserService::class, GetUserRequest::make(['id' => 1]));
            $this->fail('Expected RpcTimeoutException');
        } catch (RpcTimeoutException $e) {
            // expected
        }

        $stats = $recorder->for('UserService::GetUserRequest');
        $this->assertNotNull($stats);
        $this->assertSame(1, $stats['failed']);
    }

    public function testCallRaisesRemoteErrorAsRpcException(): void
    {
        [$amqp, $recorder] = $this->makeAmqp();
        $amqp->shouldReceive('rpc')->once()->andReturn(json_encode([
            '_rpc_error' => 'user not found',
            '_rpc_class' => 'App\\Exceptions\\NotFound',
        ]));

        $dispatcher = new RpcDispatcher($amqp);

        try {
            $dispatcher->call(UserService::class, GetUserRequest::make(['id' => 1]));
            $this->fail('Expected RpcException');
        } catch (RpcException $e) {
            $this->assertSame('user not found', $e->getMessage());
            $this->assertSame('App\\Exceptions\\NotFound', $e->remoteClass());
        }

        $stats = $recorder->for('UserService::GetUserRequest');
        $this->assertSame(1, $stats['failed']);
    }

    public function testRegisterRejectsNonServiceClasses(): void
    {
        $dispatcher = new RpcDispatcher(m::mock(Amqp::class));
        $this->expectException(InvalidArgumentException::class);
        $dispatcher->register(\stdClass::class, new \stdClass());
    }

    public function testHandleRequestDispatchesToCorrectMethod(): void
    {
        [$amqp, $recorder] = $this->makeAmqp();
        $dispatcher = new RpcDispatcher($amqp);
        $dispatcher->register(UserService::class, new UserServiceHandler());

        $message = new AMQPMessage(json_encode(['name' => 'Bob']), [
            'application_headers' => new AMQPTable([
                'x-rpc-request' => CreateUserRequest::class,
                'x-rpc-service' => UserService::class,
            ]),
            'content_type' => 'application/json',
        ]);

        $response = $dispatcher->handleRequest(UserService::class, $message);
        $this->assertSame(['id' => 99, 'name' => 'Bob'], $response);

        $stats = $recorder->for('UserService::CreateUserRequest:serve');
        $this->assertNotNull($stats);
        $this->assertSame(1, $stats['count']);
    }

    public function testServeWithoutHandlerThrows(): void
    {
        $dispatcher = new RpcDispatcher(m::mock(Amqp::class));
        $this->expectException(RuntimeException::class);
        $dispatcher->serve(UserService::class);
    }

    public function testServiceNameStripsServiceSuffix(): void
    {
        $this->assertSame('User', UserService::name());
    }
}
