<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Managers\ManagementApiClient;
use Bschmitt\Amqp\Support\ConfigurationProvider;
use Illuminate\Config\Repository;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class ManagementApiClientTest extends TestCase
{
    protected $configRepository;
    protected $config;
    protected $apiClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configRepository = m::mock(Repository::class);
        $this->configRepository->shouldReceive('has')
            ->with('amqp')
            ->andReturn(false);
        
        $this->config = new ConfigurationProvider($this->configRepository);
        
        // Mock the makeRequest method by using reflection or creating a partial mock
        $this->apiClient = m::mock(ManagementApiClient::class, [$this->config])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function testGetQueueStatistics()
    {
        $expectedResponse = [
            ['name' => 'test-queue', 'messages' => 10, 'consumers' => 2]
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('GET', m::pattern('/\/api\/queues/'))
            ->andReturn($expectedResponse);

        $result = $this->apiClient->getQueueStatistics();
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetQueueStatisticsWithQueueName()
    {
        $expectedResponse = [
            'name' => 'test-queue',
            'messages' => 10,
            'consumers' => 2
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('GET', m::pattern('/\/api\/queues\/.*\/test-queue/'))
            ->andReturn($expectedResponse);

        $result = $this->apiClient->getQueueStatistics('test-queue');
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetConnections()
    {
        $expectedResponse = [
            ['name' => '127.0.0.1:5672 -> 127.0.0.1:12345', 'state' => 'running']
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('GET', m::pattern('/\/api\/connections/'))
            ->andReturn($expectedResponse);

        $result = $this->apiClient->getConnections();
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetConnectionsWithConnectionName()
    {
        $expectedResponse = [
            'name' => '127.0.0.1:5672 -> 127.0.0.1:12345',
            'state' => 'running'
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('GET', m::pattern('/\/api\/connections\/.*/'))
            ->andReturn($expectedResponse);

        $result = $this->apiClient->getConnections('127.0.0.1:5672 -> 127.0.0.1:12345');
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetChannels()
    {
        $expectedResponse = [
            ['name' => '127.0.0.1:5672 (1)', 'number' => 1]
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('GET', m::pattern('/\/api\/channels/'))
            ->andReturn($expectedResponse);

        $result = $this->apiClient->getChannels();
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetNodes()
    {
        $expectedResponse = [
            ['name' => 'rabbit@localhost', 'running' => true]
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('GET', m::pattern('/\/api\/nodes/'))
            ->andReturn($expectedResponse);

        $result = $this->apiClient->getNodes();
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testListPolicies()
    {
        $expectedResponse = [
            ['name' => 'ha-policy', 'pattern' => '.*', 'apply-to' => 'all']
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('GET', m::pattern('/\/api\/policies/'))
            ->andReturn($expectedResponse);

        $result = $this->apiClient->listPolicies();
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetPolicy()
    {
        $expectedResponse = [
            'name' => 'ha-policy',
            'pattern' => '.*',
            'apply-to' => 'all',
            'definition' => ['ha-mode' => 'all']
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('GET', m::pattern('/\/api\/policies\/.*\/ha-policy/'))
            ->andReturn($expectedResponse);

        $result = $this->apiClient->getPolicy('ha-policy');
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testCreatePolicy()
    {
        $policyDefinition = [
            'pattern' => '^test\\.',
            'apply-to' => 'queues',
            'definition' => [
                'ha-mode' => 'all',
                'ha-sync-mode' => 'automatic'
            ],
            'priority' => 0
        ];

        $expectedResponse = [
            'name' => 'test-policy',
            'pattern' => '^test\\.',
            'apply-to' => 'queues',
            'definition' => [
                'ha-mode' => 'all',
                'ha-sync-mode' => 'automatic'
            ]
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('PUT', m::pattern('/\/api\/policies\/.*\/test-policy/'), m::type('array'))
            ->andReturn($expectedResponse);

        $result = $this->apiClient->createPolicy('test-policy', $policyDefinition);
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testCreatePolicyWithMinimalDefinition()
    {
        $policyDefinition = [
            'pattern' => '^test\\.'
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('PUT', m::pattern('/\/api\/policies\/.*\/test-policy/'), m::on(function ($data) {
                return isset($data['pattern']) 
                    && isset($data['apply-to']) 
                    && isset($data['definition']) 
                    && isset($data['priority']);
            }))
            ->andReturn([]);

        $result = $this->apiClient->createPolicy('test-policy', $policyDefinition);
        
        $this->assertIsArray($result);
    }

    public function testCreatePolicyThrowsExceptionWithoutPattern()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Policy definition must include "pattern"');

        $this->apiClient->createPolicy('test-policy', []);
    }

    public function testDeletePolicy()
    {
        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('DELETE', m::pattern('/\/api\/policies\/.*\/test-policy/'))
            ->andReturn([]);

        $this->apiClient->deletePolicy('test-policy');
        
        $this->assertTrue(true, 'Policy delete called successfully');
    }

    public function testListFeatureFlags()
    {
        $expectedResponse = [
            ['name' => 'virtual_host_metadata', 'state' => 'enabled'],
            ['name' => 'quorum_queue', 'state' => 'enabled']
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('GET', m::pattern('/\/api\/feature-flags$/'))
            ->andReturn($expectedResponse);

        $result = $this->apiClient->listFeatureFlags();
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetFeatureFlag()
    {
        $expectedResponse = [
            'name' => 'quorum_queue',
            'state' => 'enabled',
            'description' => 'Quorum queues support'
        ];

        $this->apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('GET', m::pattern('/\/api\/feature-flags\/quorum_queue/'))
            ->andReturn($expectedResponse);

        $result = $this->apiClient->getFeatureFlag('quorum_queue');
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testMakeRequestHandlesHttpError()
    {
        $apiClient = new ManagementApiClient($this->config);
        
        // Use reflection to test protected method
        $reflection = new \ReflectionClass($apiClient);
        $method = $reflection->getMethod('makeRequest');
        $method->setAccessible(true);

        // Mock curl to return error
        $this->expectException(\RuntimeException::class);
        
        // This will fail because we can't easily mock curl, but we test the error handling structure
        // In real tests, we'd use a proper HTTP mocking library or test with real API
        try {
            $method->invoke($apiClient, 'GET', 'http://invalid-host:15672/api/queues');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Management API', $e->getMessage());
            throw $e;
        }
    }
}

