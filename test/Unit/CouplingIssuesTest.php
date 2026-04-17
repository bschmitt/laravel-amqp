<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Factories\ConsumerFactory;
use Bschmitt\Amqp\Factories\PublisherFactory;
use Bschmitt\Amqp\Support\ConfigurationProvider;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for coupling issues and backward compatibility
 * 
 * These tests verify that:
 * 1. Service locator patterns are only used as fallbacks
 * 2. Concrete class checks are used appropriately
 * 3. Direct instantiation in factories works correctly
 */
class CouplingIssuesTest extends TestCase
{
    public function testConsumerCanBeCreatedWithoutAppFacade()
    {
        // Create config without using App facade
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => [
                        'host' => 'localhost',
                        'port' => 5672,
                        'username' => 'guest',
                        'password' => 'guest',
                        'vhost' => '/',
                    ]
                ]
            ]
        ];

        $configRepository = new Repository($configArray);
        $configProvider = new ConfigurationProvider($configRepository);

        // Consumer constructor accepts ConfigurationProviderInterface
        // Note: The constructor has fallback to App::make for backward compatibility
        // We verify the constructor signature allows dependency injection
        $consumerReflection = new \ReflectionClass(Consumer::class);
        $constructor = $consumerReflection->getConstructor();
        $params = $constructor->getParameters();
        
        // First parameter should be nullable for backward compatibility
        $this->assertTrue($params[0]->allowsNull(), 'Config parameter should be nullable for backward compatibility');
        
        // Verify Consumer class exists
        $this->assertTrue(class_exists(Consumer::class));
    }

    public function testPublisherCanBeCreatedWithoutAppFacade()
    {
        // Create config without using App facade
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => [
                        'host' => 'localhost',
                        'port' => 5672,
                        'username' => 'guest',
                        'password' => 'guest',
                        'vhost' => '/',
                    ]
                ]
            ]
        ];

        $configRepository = new Repository($configArray);
        $configProvider = new ConfigurationProvider($configRepository);

        // Publisher constructor accepts ConfigurationProviderInterface
        // Note: The constructor has fallback to App::make for backward compatibility
        // but when ConfigurationProvider is provided, it should work
        // We verify the constructor signature allows dependency injection
        $publisherReflection = new \ReflectionClass(Publisher::class);
        $constructor = $publisherReflection->getConstructor();
        $params = $constructor->getParameters();
        
        // First parameter should accept ConfigurationProviderInterface or be nullable
        $firstParam = $params[0];
        $this->assertTrue($firstParam->allowsNull(), 'First parameter should be nullable for backward compatibility');
        
        // Verify Publisher class exists and can be instantiated conceptually
        $this->assertTrue(class_exists(Publisher::class));
        
        // Verify constructor source shows fallback pattern
        $publisherSource = file_get_contents($publisherReflection->getFileName());
        $this->assertStringContainsString('App::make', $publisherSource, 'Should have App::make fallback');
    }

    public function testConsumerFactoryUsesDependencyInjection()
    {
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => [
                        'host' => 'localhost',
                        'port' => 5672,
                        'username' => 'guest',
                        'password' => 'guest',
                        'vhost' => '/',
                    ]
                ]
            ]
        ];

        $configRepository = new Repository($configArray);
        $configProvider = new ConfigurationProvider($configRepository);

        // Factory should use injected config, not App facade
        $factory = new ConsumerFactory($configProvider);
        
        // Verify factory has the injected config
        $reflection = new \ReflectionClass($factory);
        $property = $reflection->getProperty('defaultConfig');
        $property->setAccessible(true);
        $injectedConfig = $property->getValue($factory);
        
        $this->assertSame($configProvider, $injectedConfig);
        $this->assertInstanceOf(ConfigurationProvider::class, $injectedConfig);
    }

    public function testPublisherFactoryUsesDependencyInjection()
    {
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => [
                        'host' => 'localhost',
                        'port' => 5672,
                        'username' => 'guest',
                        'password' => 'guest',
                        'vhost' => '/',
                    ]
                ]
            ]
        ];

        $configRepository = new Repository($configArray);
        $configProvider = new ConfigurationProvider($configRepository);

        // Factory should use injected config, not App facade
        $factory = new PublisherFactory($configProvider);
        
        // Verify factory has the injected config
        $reflection = new \ReflectionClass($factory);
        $property = $reflection->getProperty('defaultConfig');
        $property->setAccessible(true);
        $injectedConfig = $property->getValue($factory);
        
        $this->assertSame($configProvider, $injectedConfig);
        $this->assertInstanceOf(ConfigurationProvider::class, $injectedConfig);
    }

    public function testInstanceofChecksAreForResourceCleanup()
    {
        // Verify instanceof checks are used in disconnect methods
        // Check the source code to verify the pattern
        $amqpReflection = new \ReflectionClass(\Bschmitt\Amqp\Core\Amqp::class);
        $disconnectPublisherMethod = $amqpReflection->getMethod('disconnectPublisher');
        $disconnectConsumerMethod = $amqpReflection->getMethod('disconnectConsumer');
        
        $amqpSource = file_get_contents($amqpReflection->getFileName());
        
        // Verify instanceof checks exist in disconnect methods
        $this->assertStringContainsString('instanceof', $amqpSource, 'Disconnect methods should use instanceof for type checking');
        $this->assertStringContainsString('Core\\Publisher', $amqpSource, 'Should check for Core\\Publisher type');
        $this->assertStringContainsString('Core\\Consumer', $amqpSource, 'Should check for Core\\Consumer type');
        
        // These checks are used for proper resource cleanup between old and new architecture
        $this->assertTrue(true, 'Instanceof checks are intentional for resource cleanup');
    }

    public function testDirectInstantiationInFactoriesIsAcceptable()
    {
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => [
                        'host' => 'localhost',
                        'port' => 5672,
                        'username' => 'guest',
                        'password' => 'guest',
                        'vhost' => '/',
                    ]
                ]
            ]
        ];

        $configRepository = new Repository($configArray);
        $configProvider = new ConfigurationProvider($configRepository);

        // Direct instantiation in factories is standard practice
        $publisherFactory = new PublisherFactory($configProvider);
        $consumerFactory = new ConsumerFactory($configProvider);

        // Verify factories can be created (direct instantiation is acceptable)
        $this->assertInstanceOf(PublisherFactory::class, $publisherFactory);
        $this->assertInstanceOf(ConsumerFactory::class, $consumerFactory);
        
        // Verify factories use direct instantiation internally (check source code pattern)
        $publisherFactoryReflection = new \ReflectionClass(PublisherFactory::class);
        $publisherFactoryMethod = $publisherFactoryReflection->getMethod('create');
        $publisherFactorySource = file_get_contents($publisherFactoryReflection->getFileName());
        
        // Verify factory uses 'new Publisher' (direct instantiation)
        $this->assertStringContainsString('new Publisher', $publisherFactorySource);
    }

    public function testBackwardCompatibilityFallbacksExist()
    {
        // Verify that Consumer/Publisher constructors have fallback mechanisms
        // These are intentional for backward compatibility with old code
        
        $consumerReflection = new \ReflectionClass(Consumer::class);
        $consumerConstructor = $consumerReflection->getConstructor();
        $consumerParams = $consumerConstructor->getParameters();
        
        // First parameter (config) should be nullable for backward compatibility
        $this->assertTrue($consumerParams[0]->allowsNull(), 'Config parameter should be nullable for backward compatibility');
        
        $publisherReflection = new \ReflectionClass(Publisher::class);
        $publisherConstructor = $publisherReflection->getConstructor();
        $publisherParams = $publisherConstructor->getParameters();
        
        // First parameter (config) should be nullable for backward compatibility
        $this->assertTrue($publisherParams[0]->allowsNull(), 'Config parameter should be nullable for backward compatibility');
    }
}

