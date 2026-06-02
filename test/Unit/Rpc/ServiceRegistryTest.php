<?php

namespace Bschmitt\Amqp\Test\Unit\Rpc;

use Bschmitt\Amqp\Rpc\ServiceRegistry;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Bschmitt\Amqp\Test\Support\Fixtures\Rpc\UserService;
use InvalidArgumentException;

class ServiceRegistryTest extends BaseTestCase
{
    public function testRegisterAndResolveByName(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('users', UserService::class);

        $this->assertTrue($registry->has('users'));
        $this->assertSame(UserService::class, $registry->resolve('users'));
        $this->assertSame(['users' => UserService::class], $registry->all());
    }

    public function testRegisterRejectsMissingClass(): void
    {
        $registry = new ServiceRegistry();
        $this->expectException(InvalidArgumentException::class);
        $registry->register('nope', 'Bschmitt\\Amqp\\NotARealClass');
    }

    public function testRegisterRejectsNonServiceClass(): void
    {
        $registry = new ServiceRegistry();
        $this->expectException(InvalidArgumentException::class);
        $registry->register('std', \stdClass::class);
    }

    public function testResolveUnknownThrows(): void
    {
        $registry = new ServiceRegistry();
        $this->expectException(InvalidArgumentException::class);
        $registry->resolve('missing');
    }

    public function testAutodiscoverUsesAliasMethod(): void
    {
        $registry = new ServiceRegistry();
        $registry->autodiscover([UserService::class, \stdClass::class]);

        $this->assertTrue($registry->has('users'));
        $this->assertSame(UserService::class, $registry->resolve('users'));
    }

    public function testClearEmptiesRegistry(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('users', UserService::class);
        $registry->clear();
        $this->assertSame([], $registry->all());
    }
}
