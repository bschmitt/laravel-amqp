<?php

namespace Bschmitt\Amqp\Test;

use \Mockery;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

class BaseTestCase extends TestCase
{

    const REPOSITORY_KEY = 'amqp';

    protected $configRepository;
    protected $defaultConfig;

    protected function setUp() : void
    {
        $amqpConfig = include dirname(__FILE__).'/../config/amqp.php';
        $this->defaultConfig = $amqpConfig['properties'][$amqpConfig['use']];

        $config = Mockery::mock('\Illuminate\Config\Repository');
        $config->shouldReceive('has')->with(self::REPOSITORY_KEY)->andReturn(true);
        $config->shouldReceive('get')->with(self::REPOSITORY_KEY)->andReturn($amqpConfig);
        $this->configRepository = $config;
    }


    protected function tearDown() : void
    {
        // necessary for Mockery to check if methods were called and with what arguments
        Mockery::close();
    }


    protected function setProtectedProperty($class, $mock, $propertyName, $value)
    {
        $reflectionClass = new \ReflectionClass($class);
        $channelProperty = $reflectionClass->getProperty($propertyName);
        $channelProperty->setAccessible(true);
        $channelProperty->setValue($mock, $value);
        $channelProperty->setAccessible(false);
    }

}
