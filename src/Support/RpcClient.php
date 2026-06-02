<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Core\Amqp;

/**
 * High-level RPC client built on {@see Amqp::rpc()}.
 *
 * Handles correlation IDs, optional JSON encoding, and structured results
 * via {@see RpcCallResult}.
 */
class RpcClient
{
    /** @var Amqp */
    protected $amqp;

    /** @var array<string, mixed> */
    protected $defaultProperties;

    /** @var int */
    protected $defaultTimeout = 30;

    /** @var bool */
    protected $json = false;

    /**
     * @param Amqp                   $amqp
     * @param array<string, mixed>   $defaultProperties
     */
    public function __construct(Amqp $amqp, array $defaultProperties = [])
    {
        $this->amqp = $amqp;
        $this->defaultProperties = $defaultProperties;
    }

    /**
     * @param bool $json When true, JSON-encode requests and decode responses.
     * @return $this
     */
    public function asJson(bool $json = true): self
    {
        $this->json = $json;

        return $this;
    }

    /**
     * @param int $seconds
     * @return $this
     */
    public function timeout(int $seconds): self
    {
        $this->defaultTimeout = $seconds;

        return $this;
    }

    /**
     * @param string               $routingKey
     * @param mixed                $request
     * @param array<string, mixed> $properties
     * @param int|null             $timeoutSeconds
     * @return RpcCallResult
     */
    public function call(
        string $routingKey,
        $request,
        array $properties = [],
        ?int $timeoutSeconds = null
    ): RpcCallResult {
        $properties = array_merge($this->defaultProperties, $properties);
        $timeout = $timeoutSeconds !== null ? $timeoutSeconds : $this->defaultTimeout;
        $correlationId = isset($properties['correlation_id'])
            ? (string) $properties['correlation_id']
            : null;

        $payload = $this->json ? json_encode($request) : $request;
        if ($this->json && $payload === false) {
            throw new \InvalidArgumentException('Failed to JSON-encode RPC request');
        }

        if ($this->json && !isset($properties['content_type'])) {
            $properties['content_type'] = 'application/json';
        }

        $start = microtime(true);
        $response = $this->amqp->rpc($routingKey, $payload, $properties, $timeout);
        $durationMs = (microtime(true) - $start) * 1000.0;

        $timedOut = $response === null;

        if ($this->json && !$timedOut && is_string($response)) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $response = $decoded;
            }
        }

        $this->amqp->rpcMetrics()->record(
            $routingKey !== '' ? $routingKey : '_anonymous',
            $durationMs,
            $timedOut
        );

        return new RpcCallResult($response, $timedOut, $correlationId, $durationMs);
    }
}
