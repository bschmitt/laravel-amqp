<?php

namespace Bschmitt\Amqp\Http\Controllers;

use Bschmitt\Amqp\Support\HealthCheck;
use Bschmitt\Amqp\Support\HealthState;
use Illuminate\Http\JsonResponse;

/**
 * Minimal HTTP endpoints for Kubernetes liveness / readiness probes.
 *
 * Routes are registered by {@see \Bschmitt\Amqp\Providers\AmqpServiceProvider}
 * when `amqp.probes.enabled` is true. Both endpoints respond with a JSON body
 * containing the underlying check results, and use HTTP 200 for healthy / 503
 * for unhealthy — the contract `kubectl` and most service meshes expect.
 */
class HealthController
{
    /** @var HealthCheck */
    protected $check;

    public function __construct(HealthCheck $check)
    {
        $this->check = $check;
    }

    public function liveness(): JsonResponse
    {
        return $this->respond($this->check->liveness());
    }

    public function readiness(): JsonResponse
    {
        return $this->respond($this->check->readiness());
    }

    public function snapshot(): JsonResponse
    {
        $payload = $this->check->snapshot();
        $status = $payload['liveness']['ok'] ? 200 : 503;
        if ($status === 200 && !$payload['readiness']['ok']) {
            $status = 200; // alive but not yet ready — still 200 for /amqp/health
        }
        return new JsonResponse($payload, $status);
    }

    /**
     * @param array<string, mixed> $result
     * @return JsonResponse
     */
    protected function respond(array $result): JsonResponse
    {
        $status = !empty($result['ok']) ? 200 : 503;
        return new JsonResponse($result, $status);
    }
}
