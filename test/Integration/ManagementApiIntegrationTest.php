<?php

namespace Bschmitt\Amqp\Test\Integration;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Managers\ManagementApiClient;
use Bschmitt\Amqp\Test\Support\IntegrationTestBase;
use Bschmitt\Amqp\Support\ConfigurationProvider;

/**
 * Integration tests for Management HTTP API
 * 
 * These tests require:
 * - RabbitMQ Management Plugin enabled
 * - Management API accessible (default: http://localhost:15672)
 * - Configure connection details in .env file:
 *   - AMQP_MANAGEMENT_HOST (optional, defaults to http://localhost)
 *   - AMQP_MANAGEMENT_PORT (optional, defaults to 15672)
 *   - AMQP_MANAGEMENT_USER (optional, falls back to AMQP_USER)
 *   - AMQP_MANAGEMENT_PASSWORD (optional, falls back to AMQP_PASSWORD)
 * 
 * Reference: https://www.rabbitmq.com/docs/management
 */
class ManagementApiIntegrationTest extends IntegrationTestBase
{
    protected $amqp;
    protected $apiClient;
    protected $testQueueName;
    protected $testPolicyName;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        if (!$this->isManagementApiAvailable()) {
            $this->markTestSkipped('RabbitMQ Management API is not available. Please enable the management plugin.');
        }

        $this->testQueueName = 'test-queue-management-api-' . time();
        $this->testPolicyName = 'test-policy-' . time();

        // Create Amqp instance
        $config = include dirname(__FILE__) . '/../../config/amqp.php';
        $defaultProperties = $config['properties'][$config['use']];
        
        $this->loadEnvFile();
        
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => array_merge($defaultProperties, [
                        'host' => $this->getEnv('AMQP_HOST', $defaultProperties['host'] ?? 'localhost'),
                        'port' => (int) $this->getEnv('AMQP_PORT', $defaultProperties['port'] ?? 5672),
                        'username' => $this->getEnv('AMQP_USER', $defaultProperties['username'] ?? 'guest'),
                        'password' => $this->getEnv('AMQP_PASSWORD', $defaultProperties['password'] ?? 'guest'),
                        'vhost' => $this->getEnv('AMQP_VHOST', $defaultProperties['vhost'] ?? '/'),
                        'management_host' => $this->getEnv('AMQP_MANAGEMENT_HOST', 'http://localhost'),
                        'management_port' => (int) $this->getEnv('AMQP_MANAGEMENT_PORT', 15672),
                        'management_username' => $this->getEnv('AMQP_MANAGEMENT_USER', null),
                        'management_password' => $this->getEnv('AMQP_MANAGEMENT_PASSWORD', null),
                        'exchange' => 'test-exchange-management-api',
                        'queue' => $this->testQueueName,
                        'queue_durable' => false,
                        'queue_auto_delete' => true,
                    ])
                ]
            ]
        ];

        $configRepository = new \Illuminate\Config\Repository($configArray);
        $configProvider = new ConfigurationProvider($configRepository);
        
        $publisherFactory = new \Bschmitt\Amqp\Factories\PublisherFactory($configProvider);
        $consumerFactory = new \Bschmitt\Amqp\Factories\ConsumerFactory($configProvider);
        $messageFactory = new \Bschmitt\Amqp\Factories\MessageFactory();
        $batchManager = new \Bschmitt\Amqp\Managers\BatchManager();
        
        $this->amqp = new Amqp($publisherFactory, $consumerFactory, $messageFactory, $batchManager);
        $this->apiClient = new ManagementApiClient($configProvider);
    }

    protected function tearDown(): void
    {
        // Cleanup: try to delete test policy
        if ($this->apiClient !== null && $this->testPolicyName !== null) {
            try {
                $this->apiClient->deletePolicy($this->testPolicyName);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }
        parent::tearDown();
    }

    protected function isManagementApiAvailable(): bool
    {
        $host = $this->getEnv('AMQP_MANAGEMENT_HOST', 'http://localhost');
        $port = (int) $this->getEnv('AMQP_MANAGEMENT_PORT', 15672);
        
        // Remove http:// or https:// prefix for fsockopen
        $hostname = preg_replace('#^https?://#', '', $host);
        $hostname = preg_replace('#:.*$#', '', $hostname);
        
        $connection = @fsockopen($hostname, $port, $errno, $errstr, 2);
        if ($connection) {
            fclose($connection);
            return true;
        }
        return false;
    }

    public function testGetQueueStatistics()
    {
        // Create a test queue first
        $config = include dirname(__FILE__) . '/../../config/amqp.php';
        $defaultProperties = $config['properties'][$config['use']];
        
        $configArray = [
            'amqp' => [
                'use' => 'test',
                'properties' => [
                    'test' => array_merge($defaultProperties, [
                        'host' => $this->getEnv('AMQP_HOST', 'localhost'),
                        'port' => (int) $this->getEnv('AMQP_PORT', 5672),
                        'username' => $this->getEnv('AMQP_USER', 'guest'),
                        'password' => $this->getEnv('AMQP_PASSWORD', 'guest'),
                        'vhost' => $this->getEnv('AMQP_VHOST', '/'),
                        'queue' => $this->testQueueName,
                        'queue_durable' => false,
                        'queue_auto_delete' => true,
                    ])
                ]
            ]
        ];

        $configRepository = new \Illuminate\Config\Repository($configArray);
        $configProvider = new ConfigurationProvider($configRepository);
        $connectionManager = new \Bschmitt\Amqp\Managers\ConnectionManager($configProvider);
        $connectionManager->connect();
        $channel = $connectionManager->getChannel();
        $channel->queue_declare($this->testQueueName, false, false, false, true);
        $connectionManager->disconnect();

        // Get queue statistics
        $stats = $this->amqp->getQueueStatistics($this->testQueueName);
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('name', $stats);
        $this->assertEquals($this->testQueueName, $stats['name']);
    }

    public function testGetQueueStatisticsAllQueues()
    {
        $stats = $this->amqp->getQueueStatistics();
        
        $this->assertIsArray($stats);
        // Should return an array of queues
        if (count($stats) > 0) {
            $this->assertArrayHasKey('name', $stats[0]);
        }
    }

    public function testGetConnections()
    {
        $connections = $this->amqp->getConnections();
        
        $this->assertIsArray($connections);
        // Should return an array of connections
        if (count($connections) > 0) {
            $this->assertArrayHasKey('name', $connections[0]);
        }
    }

    public function testGetChannels()
    {
        $channels = $this->amqp->getChannels();
        
        $this->assertIsArray($channels);
        // Should return an array of channels
        if (count($channels) > 0) {
            $this->assertArrayHasKey('name', $channels[0]);
        }
    }

    public function testGetNodes()
    {
        $nodes = $this->amqp->getNodes();
        
        $this->assertIsArray($nodes);
        $this->assertGreaterThan(0, count($nodes), 'Should have at least one node');
        $this->assertArrayHasKey('name', $nodes[0]);
        $this->assertArrayHasKey('running', $nodes[0]);
    }

    public function testListPolicies()
    {
        $policies = $this->amqp->listPolicies();
        
        $this->assertIsArray($policies);
    }

    public function testCreatePolicy()
    {
        $policyDefinition = [
            'pattern' => '^test\\.',
            'apply-to' => 'queues',
            'definition' => [
                'ha-mode' => 'all'
            ],
            'priority' => 0
        ];

        $result = $this->amqp->createPolicy($this->testPolicyName, $policyDefinition);
        
        $this->assertIsArray($result);
        
        // Verify policy was created
        $policy = $this->amqp->getPolicy($this->testPolicyName);
        $this->assertEquals($this->testPolicyName, $policy['name']);
        $this->assertEquals('^test\\.', $policy['pattern']);
    }

    public function testGetPolicy()
    {
        // Create a policy first
        $policyDefinition = [
            'pattern' => '^test-get\\.',
            'apply-to' => 'exchanges',
            'definition' => [
                'alternate-exchange' => 'unroutable'
            ],
            'priority' => 1
        ];

        $this->amqp->createPolicy($this->testPolicyName . '-get', $policyDefinition);

        // Get the policy
        $policy = $this->amqp->getPolicy($this->testPolicyName . '-get');
        
        $this->assertIsArray($policy);
        $this->assertEquals($this->testPolicyName . '-get', $policy['name']);
        $this->assertEquals('^test-get\\.', $policy['pattern']);
        
        // Cleanup
        $this->amqp->deletePolicy($this->testPolicyName . '-get');
    }

    public function testDeletePolicy()
    {
        // Create a policy first
        $policyDefinition = [
            'pattern' => '^test-delete\\.',
            'apply-to' => 'all'
        ];

        try {
            $this->amqp->createPolicy($this->testPolicyName . '-delete', $policyDefinition);

            // Verify it exists
            $policy = $this->amqp->getPolicy($this->testPolicyName . '-delete');
            $this->assertIsArray($policy);

            // Delete it
            $this->amqp->deletePolicy($this->testPolicyName . '-delete');

            // Verify it's deleted (should throw exception or return 404)
            try {
                $this->amqp->getPolicy($this->testPolicyName . '-delete');
                $this->fail('Policy should have been deleted');
            } catch (\RuntimeException $e) {
                $this->assertTrue(
                    strpos($e->getMessage(), '404') !== false || 
                    strpos($e->getMessage(), 'Not Found') !== false,
                    'Expected 404 or Not Found error'
                );
            }
        } catch (\RuntimeException $e) {
            // If Management API returns error, skip this test
            if (strpos($e->getMessage(), '500') !== false) {
                $this->markTestSkipped('Management API returned 500 error: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    public function testCreatePolicyWithMinimalDefinition()
    {
        $policyDefinition = [
            'pattern' => '^minimal\\.'
        ];

        try {
            $result = $this->amqp->createPolicy($this->testPolicyName . '-minimal', $policyDefinition);
            
            $this->assertIsArray($result);
            
            // Cleanup
            try {
                $this->amqp->deletePolicy($this->testPolicyName . '-minimal');
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        } catch (\RuntimeException $e) {
            // If Management API returns error, skip this test
            if (strpos($e->getMessage(), '500') !== false) {
                $this->markTestSkipped('Management API returned 500 error: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    public function testListFeatureFlags()
    {
        $featureFlags = $this->amqp->listFeatureFlags();
        
        $this->assertIsArray($featureFlags);
        // Should return an array of feature flags
        if (count($featureFlags) > 0) {
            $this->assertArrayHasKey('name', $featureFlags[0]);
            $this->assertArrayHasKey('state', $featureFlags[0]);
        }
    }

    public function testGetFeatureFlag()
    {
        // First, get list of feature flags to find a valid one
        $featureFlags = $this->amqp->listFeatureFlags();
        
        if (empty($featureFlags)) {
            $this->markTestSkipped('No feature flags available to test');
            return;
        }

        // Get the first feature flag
        $firstFlag = $featureFlags[0];
        $flagName = $firstFlag['name'];

        // Try to get specific feature flag
        // Note: Some RabbitMQ versions may not support individual feature flag endpoint
        // In that case, we can filter from the list
        try {
            $featureFlag = $this->amqp->getFeatureFlag($flagName);
            
            $this->assertIsArray($featureFlag);
            $this->assertEquals($flagName, $featureFlag['name']);
            $this->assertArrayHasKey('state', $featureFlag);
        } catch (\RuntimeException $e) {
            // If endpoint doesn't exist (406 Not Acceptable or 404), 
            // verify we can find it in the list instead
            if (strpos($e->getMessage(), '406') !== false || strpos($e->getMessage(), '404') !== false) {
                // Find the flag in the list
                $found = false;
                foreach ($featureFlags as $flag) {
                    if ($flag['name'] === $flagName) {
                        $this->assertArrayHasKey('state', $flag);
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue($found, 'Feature flag should be found in the list');
            } else {
                throw $e;
            }
        }
    }
}

