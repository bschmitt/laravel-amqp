<?php

namespace Bschmitt\Amqp\Support;

/**
 * Process-wide health snapshot for AMQP workers.
 *
 * Mirrors the Kubernetes split between **liveness** (is the process alive and
 * making forward progress?) and **readiness** (is it currently able to handle
 * traffic?). Workers update this state from {@see ConsumerLifecycle} hooks,
 * and HTTP / CLI probes read it back.
 *
 * Backed by an in-process singleton plus an optional state file so an exec
 * probe (`amqp:health` from a kubelet exec probe) and an HTTP probe can both
 * see the same view without a shared memory primitive.
 */
class HealthState
{
    /** @var HealthState|null */
    protected static $instance;

    /** @var bool */
    protected $alive = true;

    /** @var bool */
    protected $ready = false;

    /** @var float */
    protected $startedAt;

    /** @var float */
    protected $lastHeartbeat;

    /** @var string|null */
    protected $reason;

    /** @var int */
    protected $messagesProcessed = 0;

    /** @var int */
    protected $errors = 0;

    /** @var string|null */
    protected $statePath;

    public function __construct(?string $statePath = null)
    {
        $this->startedAt = microtime(true);
        $this->lastHeartbeat = $this->startedAt;
        $this->statePath = $statePath;
    }

    /**
     * Singleton accessor. The first call wins; later calls return the same
     * instance, optionally re-pointing the state file when provided.
     */
    public static function instance(?string $statePath = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($statePath);
        } elseif ($statePath !== null) {
            self::$instance->statePath = $statePath;
        }

        return self::$instance;
    }

    /**
     * Reset the singleton (test helper).
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function markStarting(): void
    {
        $this->alive = true;
        $this->ready = false;
        $this->reason = 'starting';
        $this->touch();
        $this->persist();
    }

    public function markReady(?string $reason = null): void
    {
        $this->alive = true;
        $this->ready = true;
        $this->reason = $reason;
        $this->touch();
        $this->persist();
    }

    public function markNotReady(?string $reason = null): void
    {
        $this->ready = false;
        $this->reason = $reason;
        $this->persist();
    }

    public function markDead(?string $reason = null): void
    {
        $this->alive = false;
        $this->ready = false;
        $this->reason = $reason;
        $this->persist();
    }

    public function heartbeat(): void
    {
        $this->lastHeartbeat = microtime(true);
        $this->persist();
    }

    public function recordProcessed(): void
    {
        $this->messagesProcessed++;
        $this->touch();
        $this->persist();
    }

    public function recordError(): void
    {
        $this->errors++;
        $this->touch();
        $this->persist();
    }

    /**
     * Age in seconds since the last call to {@see touch()} / heartbeat.
     */
    public function ageSinceHeartbeat(?float $now = null): float
    {
        $now = $now !== null ? $now : microtime(true);
        return max(0.0, $now - $this->lastHeartbeat);
    }

    public function isAlive(): bool
    {
        return $this->alive;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public function lastHeartbeat(): float
    {
        return $this->lastHeartbeat;
    }

    public function messagesProcessed(): int
    {
        return $this->messagesProcessed;
    }

    public function errors(): int
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'alive' => $this->alive,
            'ready' => $this->ready,
            'reason' => $this->reason,
            'started_at' => $this->startedAt,
            'last_heartbeat' => $this->lastHeartbeat,
            'age_seconds' => $this->ageSinceHeartbeat(),
            'messages_processed' => $this->messagesProcessed,
            'errors' => $this->errors,
            'uptime_seconds' => max(0.0, microtime(true) - $this->startedAt),
        ];
    }

    /**
     * Load a previously persisted snapshot from disk (best-effort).
     */
    public function hydrateFromDisk(): bool
    {
        if ($this->statePath === null || !is_file($this->statePath)) {
            return false;
        }

        $raw = @file_get_contents($this->statePath);
        if ($raw === false) {
            return false;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return false;
        }

        $this->alive = (bool) ($decoded['alive'] ?? $this->alive);
        $this->ready = (bool) ($decoded['ready'] ?? $this->ready);
        $this->reason = isset($decoded['reason']) ? (string) $decoded['reason'] : null;
        $this->startedAt = (float) ($decoded['started_at'] ?? $this->startedAt);
        $this->lastHeartbeat = (float) ($decoded['last_heartbeat'] ?? $this->lastHeartbeat);
        $this->messagesProcessed = (int) ($decoded['messages_processed'] ?? 0);
        $this->errors = (int) ($decoded['errors'] ?? 0);

        return true;
    }

    public function setStatePath(?string $path): void
    {
        $this->statePath = $path;
    }

    public function statePath(): ?string
    {
        return $this->statePath;
    }

    protected function touch(): void
    {
        $this->lastHeartbeat = microtime(true);
    }

    protected function persist(): void
    {
        if ($this->statePath === null) {
            return;
        }
        $dir = dirname($this->statePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $this->statePath,
            (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
