<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Support\HealthCheck;
use Bschmitt\Amqp\Support\HealthState;
use Illuminate\Console\Command;

/**
 * Health probe Artisan command for sidecars and Kubernetes exec probes.
 *
 *   php artisan amqp:health                  # readiness (default)
 *   php artisan amqp:health --probe=live     # liveness only
 *   php artisan amqp:health --probe=ready    # readiness only
 *   php artisan amqp:health --all            # combined snapshot
 *   php artisan amqp:health --queue=orders --backlog=1000
 *
 * Exit codes:
 *   - 0 when the probed check is healthy
 *   - 1 when unhealthy
 */
class AmqpHealthCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:health
                            {--probe=ready : Which probe to run (live|ready)}
                            {--all : Output combined snapshot instead of a single probe}
                            {--queue=* : Queue(s) to check for existence and depth}
                            {--backlog= : Max acceptable depth for watched queues}
                            {--heartbeat-age=60 : Max heartbeat age (seconds) for liveness}
                            {--state-file= : Override path to the persisted HealthState file}
                            {--json : Force JSON output (default) — kept for forwards-compatible scripting}
                            {--text : Emit a one-line human summary instead of JSON}';

    /** @var string */
    protected $description = 'Run AMQP worker health probes (liveness / readiness) for K8s sidecar checks';

    public function handle(HealthCheck $check, HealthState $state): int
    {
        $statePath = (string) ($this->option('state-file') ?: '');
        if ($statePath !== '') {
            $state->setStatePath($statePath);
        }
        if ($state->statePath() !== null) {
            $state->hydrateFromDisk();
        }

        $check->maxHeartbeatAge((float) $this->option('heartbeat-age'));

        $queues = array_values(array_filter((array) $this->option('queue'), function ($q) {
            return $q !== '' && $q !== null;
        }));
        if ($queues !== []) {
            $check->watchQueues($queues);
        }

        $backlog = $this->option('backlog');
        if ($backlog !== null && $backlog !== '') {
            $check->maxBacklog((int) $backlog);
        }

        $asText = (bool) $this->option('text');

        if ((bool) $this->option('all')) {
            $payload = $check->snapshot();
            $ok = $payload['liveness']['ok'] && $payload['readiness']['ok'];
            $this->emit($payload, $asText, 'snapshot');
            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $probe = strtolower((string) $this->option('probe'));
        $result = $probe === 'live' ? $check->liveness() : $check->readiness();

        $this->emit($result, $asText, $probe === 'live' ? 'liveness' : 'readiness');

        return !empty($result['ok']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string, mixed> $payload
     * @param bool                 $asText
     * @param string               $kind
     * @return void
     */
    protected function emit(array $payload, bool $asText, string $kind): void
    {
        if (!$asText) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        if ($kind === 'snapshot') {
            $live = !empty($payload['liveness']['ok']) ? 'OK' : 'FAIL';
            $ready = !empty($payload['readiness']['ok']) ? 'OK' : 'FAIL';
            $this->line(sprintf('liveness=%s readiness=%s', $live, $ready));
            foreach (['liveness', 'readiness'] as $section) {
                foreach ((array) ($payload[$section]['checks'] ?? []) as $check) {
                    $this->line(sprintf(
                        '  [%s] %-22s %s',
                        !empty($check['ok']) ? 'OK  ' : 'FAIL',
                        (string) ($check['name'] ?? '?'),
                        (string) ($check['message'] ?? '')
                    ));
                }
            }
            return;
        }

        $status = !empty($payload['ok']) ? 'OK' : 'FAIL';
        $this->line(sprintf('%s: %s', $kind, $status));
        foreach ((array) ($payload['checks'] ?? []) as $check) {
            $this->line(sprintf(
                '  [%s] %-22s %s',
                !empty($check['ok']) ? 'OK  ' : 'FAIL',
                (string) ($check['name'] ?? '?'),
                (string) ($check['message'] ?? '')
            ));
        }
    }
}
