<?php

namespace Bschmitt\Amqp\Managers;

use Bschmitt\Amqp\Contracts\ConfigurationProviderInterface;

/**
 * RabbitMQ Management HTTP API Client
 * 
 * Provides access to RabbitMQ Management API for:
 * - Queue statistics
 * - Connection monitoring
 * - Channel monitoring
 * - Node information
 * - Policy management
 * 
 * Reference: https://www.rabbitmq.com/docs/management
 */
class ManagementApiClient
{
    /**
     * @var ConfigurationProviderInterface
     */
    protected $config;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var string
     */
    protected $username;

    /**
     * @var string
     */
    protected $password;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var string
     */
    protected $vhost;

    /**
     * @param ConfigurationProviderInterface $config
     */
    public function __construct(ConfigurationProviderInterface $config)
    {
        $this->config = $config;
        $this->baseUrl = $this->config->getProperty('management_host', 'http://localhost');
        $this->port = (int) $this->config->getProperty('management_port', 15672);
        $this->username = $this->config->getProperty('management_username', $this->config->getProperty('username', 'guest'));
        $this->password = $this->config->getProperty('management_password', $this->config->getProperty('password', 'guest'));
        $this->vhost = $this->config->getProperty('vhost', '/');
    }

    /**
     * Get queue statistics
     *
     * @param string|null $queueName Queue name (optional, returns all queues if null)
     * @param string|null $vhost Virtual host (optional, uses config vhost if null)
     * @return array
     */
    public function getQueueStatistics(?string $queueName = null, ?string $vhost = null): array
    {
        $vhost = $vhost ?? $this->encodeVhost($this->vhost);
        
        if ($queueName !== null) {
            $url = $this->buildUrl("/api/queues/{$vhost}/{$queueName}");
        } else {
            $url = $this->buildUrl("/api/queues/{$vhost}");
        }
        
        return $this->makeRequest('GET', $url);
    }

    /**
     * Get connection information
     *
     * @param string|null $connectionName Connection name (optional, returns all connections if null)
     * @return array
     */
    public function getConnections(?string $connectionName = null): array
    {
        if ($connectionName !== null) {
            $url = $this->buildUrl("/api/connections/{$connectionName}");
        } else {
            $url = $this->buildUrl("/api/connections");
        }
        
        return $this->makeRequest('GET', $url);
    }

    /**
     * Get channel information
     *
     * @param string|null $channelName Channel name (optional, returns all channels if null)
     * @return array
     */
    public function getChannels(?string $channelName = null): array
    {
        if ($channelName !== null) {
            $url = $this->buildUrl("/api/channels/{$channelName}");
        } else {
            $url = $this->buildUrl("/api/channels");
        }
        
        return $this->makeRequest('GET', $url);
    }

    /**
     * Get node information
     *
     * @param string|null $nodeName Node name (optional, returns all nodes if null)
     * @return array
     */
    public function getNodes(?string $nodeName = null): array
    {
        if ($nodeName !== null) {
            $url = $this->buildUrl("/api/nodes/{$nodeName}");
        } else {
            $url = $this->buildUrl("/api/nodes");
        }
        
        return $this->makeRequest('GET', $url);
    }

    /**
     * List all policies
     *
     * @param string|null $vhost Virtual host (optional, uses config vhost if null)
     * @return array
     */
    public function listPolicies(?string $vhost = null): array
    {
        $vhost = $vhost ?? $this->encodeVhost($this->vhost);
        $url = $this->buildUrl("/api/policies/{$vhost}");
        
        return $this->makeRequest('GET', $url);
    }

    /**
     * Get a specific policy
     *
     * @param string $policyName Policy name
     * @param string|null $vhost Virtual host (optional, uses config vhost if null)
     * @return array
     */
    public function getPolicy(string $policyName, ?string $vhost = null): array
    {
        $vhost = $vhost ?? $this->encodeVhost($this->vhost);
        $url = $this->buildUrl("/api/policies/{$vhost}/{$policyName}");
        
        return $this->makeRequest('GET', $url);
    }

    /**
     * Create or update a policy
     *
     * @param string $policyName Policy name
     * @param array $definition Policy definition
     * @param string|null $vhost Virtual host (optional, uses config vhost if null)
     * @return array
     */
    public function createPolicy(string $policyName, array $definition, ?string $vhost = null): array
    {
        $vhost = $vhost ?? $this->encodeVhost($this->vhost);
        $url = $this->buildUrl("/api/policies/{$vhost}/{$policyName}");
        
        // Ensure required fields
        if (!isset($definition['pattern'])) {
            throw new \InvalidArgumentException('Policy definition must include "pattern"');
        }
        if (!isset($definition['apply-to'])) {
            $definition['apply-to'] = 'all';
        }
        if (!isset($definition['definition'])) {
            $definition['definition'] = [];
        }
        if (!isset($definition['priority'])) {
            $definition['priority'] = 0;
        }
        
        return $this->makeRequest('PUT', $url, $definition);
    }

    /**
     * Delete a policy
     *
     * @param string $policyName Policy name
     * @param string|null $vhost Virtual host (optional, uses config vhost if null)
     * @return void
     */
    public function deletePolicy(string $policyName, ?string $vhost = null): void
    {
        $vhost = $vhost ?? $this->encodeVhost($this->vhost);
        $url = $this->buildUrl("/api/policies/{$vhost}/{$policyName}");
        
        $this->makeRequest('DELETE', $url);
    }

    /**
     * Make HTTP request to Management API
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param array|null $data Request body data (for POST/PUT)
     * @return array
     * @throws \Exception
     */
    protected function makeRequest(string $method, string $url, ?array $data = null): array
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$this->username}:{$this->password}",
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        
        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException("Management API request failed: {$error}");
        }
        
        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['reason'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Management API error: {$errorMessage}", $httpCode);
        }
        
        if ($method === 'DELETE' && $httpCode === 204) {
            return [];
        }
        
        $decoded = json_decode($response, true);
        return $decoded ?? [];
    }

    /**
     * Build full URL
     *
     * @param string $path API path
     * @return string
     */
    protected function buildUrl(string $path): string
    {
        return rtrim($this->baseUrl, '/') . ':' . $this->port . $path;
    }

    /**
     * Encode vhost for URL
     *
     * @param string $vhost Virtual host
     * @return string
     */
    protected function encodeVhost(string $vhost): string
    {
        return rawurlencode($vhost);
    }
}

