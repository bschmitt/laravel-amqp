<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Publisher;
use \Mockery;
use Illuminate\Config\Repository;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class SimpleTest extends \PHPUnit_Framework_TestCase
{
    private $REPOSITORY_KEY = 'amqp';
    private $configRepository;
    private $defaultConfig;

    private $publisherMock;
    private $connectionMock;
    private $channelMock;

    protected function setUp()
    {
        $amqpConfig = include dirname(__FILE__).'/../config/amqp.php';
        $this->defaultConfig = $amqpConfig['properties'][$amqpConfig['use']];
        $config = Mockery::mock('\Illuminate\Config\Repository');
        $config->shouldReceive('has')->with($this->REPOSITORY_KEY)->andReturn(true);
        $config->shouldReceive('get')->with($this->REPOSITORY_KEY)->andReturn($amqpConfig);
        $this->configRepository = $config;

        // partial mock of \Bschmitt\Amqp\Publisher
        // we want all methods except [connect,getChannel] to be real
        $this->publisherMock = Mockery::mock('\Bschmitt\Amqp\Publisher[connect,getChannel]', [$this->configRepository]);
        // set connection and channel properties
        $this->channelMock = Mockery::mock('\PhpAmqpLib\Channel\AMQPChannel');
        $this->connectionMock = Mockery::mock('\PhpAmqpLib\Connection\AMQPSSLConnection');
        // channel and connection are both protected and without changing the source this was the only way to mock them
        $this->setProtectedProperty('\Bschmitt\Amqp\Publisher', $this->publisherMock, 'channel', $this->channelMock);
        $this->setProtectedProperty('\Bschmitt\Amqp\Publisher', $this->publisherMock, 'connection', $this->connectionMock);
    }

    protected function tearDown()
    {
        // necessary for Mockery to check if methods were called and with what arguments
        Mockery::close();
    }

    public function testClassInstance()
    {
    }

    public function testSetupPublisher()
    {
        $this->connectionMock->shouldReceive('set_close_on_destruct')->with(true)->times(1);

        $this->channelMock->shouldReceive('exchange_declare')->with(
            $this->defaultConfig['exchange'],
            $this->defaultConfig['exchange_type'],
            $this->defaultConfig['exchange_passive'],
            $this->defaultConfig['exchange_durable'],
            $this->defaultConfig['exchange_auto_delete'],
            $this->defaultConfig['exchange_internal'],
            $this->defaultConfig['exchange_nowait'],
            $this->defaultConfig['exchange_properties']
        )->times(1);

        $this->publisherMock->shouldReceive('connect')->times(1);

        $this->publisherMock->setup();

    }

    /**
     * @expectedException Bschmitt\Amqp\Exception\Configuration
     */
    public function testIfEmptyExchangeThrowsAnException()
    {
        $this->publisherMock->mergeProperties(['exchange' => '']);
        $this->publisherMock->shouldReceive('connect');
        // will throw an Exception
        $this->publisherMock->setup();
    }

    public function testIfQueueGetsDeclaredAndBoundIfInConfig()
    {
        $queueName = 'amqp-test';
        $routing = 'routing-test';

        $this->publisherMock->mergeProperties([
            'queue' => $queueName, 
            'queue_force_declare' => true,
            'routing' => $routing
        ]);
        $this->channelMock->shouldReceive('exchange_declare');
        $this->publisherMock->shouldReceive('connect');

        $this->channelMock->shouldReceive('queue_declare')
                          ->with(
                            $queueName,
                            $this->defaultConfig['queue_passive'],
                            $this->defaultConfig['queue_durable'],
                            $this->defaultConfig['queue_exclusive'],
                            $this->defaultConfig['queue_auto_delete'],
                            $this->defaultConfig['queue_nowait'],
                            $this->defaultConfig['queue_properties']
                          )
                          ->andReturn([$queueName, 4])
                          ->times(1);
        $this->channelMock->shouldReceive('queue_bind')
                          ->with(
                              $queueName,
                              $this->defaultConfig['exchange'],
                              $routing
                            )
                          ->times(1);
        $this->connectionMock->shouldReceive('set_close_on_destruct')->with(true)->times(1);
        $this->publisherMock->setup();
    }

    private function setProtectedProperty($class, $mock, $propertyName, $value)
    {
        $reflectionClass = new \ReflectionClass($class);
        $channelProperty = $reflectionClass->getProperty($propertyName);
        $channelProperty->setAccessible(true);
        $channelProperty->setValue($mock, $value);
        $channelProperty->setAccessible(false);
    }

}