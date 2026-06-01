<?php

namespace Bschmitt\Amqp\Test\Unit\Console;

use Bschmitt\Amqp\Console\HandlerResolver;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Test\Support\Fixtures\InvokableHandler;
use Bschmitt\Amqp\Test\Support\Fixtures\RecordingHandler;
use Illuminate\Container\Container;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HandlerResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingHandler::reset();
        InvokableHandler::reset();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testResolvesMessageHandlerInterfaceClassFromString(): void
    {
        $resolver = new HandlerResolver(new Container());

        $callable = $resolver->resolve(RecordingHandler::class);

        $message = new AMQPMessage('x');
        $consumer = Mockery::mock(ConsumerInterface::class);
        $callable($message, $consumer);

        $this->assertCount(1, RecordingHandler::$calls);
        $this->assertSame($message, RecordingHandler::$calls[0]['message']);
    }

    public function testResolvesInvokableHandlerFromString(): void
    {
        $resolver = new HandlerResolver(new Container());

        $callable = $resolver->resolve(InvokableHandler::class);

        $message = new AMQPMessage('y');
        $consumer = Mockery::mock(ConsumerInterface::class);
        $callable($message, $consumer);

        $this->assertCount(1, InvokableHandler::$calls);
    }

    public function testResolvesClosureUnchanged(): void
    {
        $resolver = new HandlerResolver();

        $hit = false;
        $closure = function () use (&$hit) {
            $hit = true;
        };

        $callable = $resolver->resolve($closure);
        $callable(new AMQPMessage('z'), Mockery::mock(ConsumerInterface::class));

        $this->assertTrue($hit);
    }

    public function testResolvesAlreadyInstantiatedHandlerObject(): void
    {
        $resolver = new HandlerResolver();

        $callable = $resolver->resolve(new RecordingHandler());
        $callable(new AMQPMessage('w'), Mockery::mock(ConsumerInterface::class));

        $this->assertCount(1, RecordingHandler::$calls);
    }

    public function testThrowsOnUnknownClass(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        (new HandlerResolver(new Container()))->resolve('No\\Such\\Handler');
    }

    public function testThrowsOnNonHandlerObject(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must implement');

        (new HandlerResolver())->resolve(new \stdClass());
    }

    public function testResolverUsesContainerForConstructorInjection(): void
    {
        $container = new Container();
        $resolved = false;

        $container->resolving(RecordingHandler::class, function () use (&$resolved) {
            $resolved = true;
        });

        $resolver = new HandlerResolver($container);
        $resolver->resolve(RecordingHandler::class);

        $this->assertTrue($resolved, 'Container should be used to make() the handler');
    }
}
