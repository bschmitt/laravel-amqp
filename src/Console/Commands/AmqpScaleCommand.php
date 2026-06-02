<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\AutoscalingAdvisor;
use Illuminate\Console\Command;

/**
 * Print autoscaling recommendations for one or more queues.
 *
 *   php artisan amqp:scale orders
 *   php artisan amqp:scale orders orders.priority --per-consumer=50 --max=20
 *   php artisan amqp:scale orders --keda           # output the KEDA trigger
 *   php artisan amqp:scale orders --json
 *
 * Pulls live snapshots from `Amqp::queueMetrics()` and pipes them through
 * {@see AutoscalingAdvisor}. Exits non-zero when any queue recommends a
 * scale-up — CI / cron friendly.
 */
class AmqpScaleCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:scale
                            {queues* : One or more queue names to analyse}
                            {--vhost= : Vhost override}
                            {--per-consumer=100 : Target depth per consumer}
                            {--min=1 : Minimum replicas}
                            {--max=10 : Maximum replicas}
                            {--lag-seconds=30 : Lag threshold that forces a scale-up}
                            {--connection= : Override connection key}
                            {--keda : Emit only the KEDA trigger specs}
                            {--json : Emit the full recommendation set as JSON}
                            {--fail-on-scale-up : Exit 1 when any queue recommends scaling up}';

    /** @var string */
    protected $description = 'Compute consumer autoscaling recommendations for one or more queues';

    public function handle(Amqp $amqp): int
    {
        $queues = (array) $this->argument('queues');
        if ($queues === []) {
            $this->error('At least one queue name is required.');
            return self::INVALID;
        }

        $vhost = (string) ($this->option('vhost') ?: '');
        $vhost = $vhost === '' ? null : $vhost;
        $connection = (string) ($this->option('connection') ?: '');
        $properties = [];
        if ($connection !== '') {
            $properties['use'] = $connection;
        }

        $advisor = (new AutoscalingAdvisor())
            ->minReplicas((int) $this->option('min'))
            ->maxReplicas((int) $this->option('max'))
            ->messagesPerConsumer((int) $this->option('per-consumer'))
            ->maxLagSeconds((float) $this->option('lag-seconds'));

        $recommendations = [];
        $anyScaleUp = false;
        foreach ($queues as $queue) {
            try {
                $metrics = $amqp->queueMetrics((string) $queue, $vhost, $properties);
                $advice = $advisor->advise($metrics);
            } catch (\Throwable $e) {
                $advice = [
                    'queue' => (string) $queue,
                    'error' => $e->getMessage(),
                ];
            }
            if (($advice['action'] ?? null) === 'scale_up') {
                $anyScaleUp = true;
            }
            $recommendations[] = $advice;
        }

        if ((bool) $this->option('keda')) {
            $triggers = array_map(static function ($r) {
                return $r['keda'] ?? ['error' => $r['error'] ?? 'unknown'];
            }, $recommendations);
            $this->line((string) json_encode($triggers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $anyScaleUp && (bool) $this->option('fail-on-scale-up') ? self::FAILURE : self::SUCCESS;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($recommendations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $anyScaleUp && (bool) $this->option('fail-on-scale-up') ? self::FAILURE : self::SUCCESS;
        }

        $rows = [];
        foreach ($recommendations as $r) {
            if (isset($r['error'])) {
                $rows[] = [(string) ($r['queue'] ?? '?'), 'ERR', '', '', '', $r['error']];
                continue;
            }
            $rows[] = [
                (string) $r['queue'],
                (string) $r['messages'],
                isset($r['lag_seconds']) && $r['lag_seconds'] !== null
                    ? number_format((float) $r['lag_seconds'], 1) . 's'
                    : '–',
                (string) $r['current_consumers'],
                (string) $r['desired_consumers'],
                (string) $r['action'],
            ];
        }
        $this->table(
            ['queue', 'messages', 'lag', 'consumers', 'desired', 'action'],
            $rows
        );
        foreach ($recommendations as $r) {
            foreach ((array) ($r['reasons'] ?? []) as $reason) {
                $this->line(sprintf('  %s :: %s', $r['queue'], $reason));
            }
        }

        return $anyScaleUp && (bool) $this->option('fail-on-scale-up') ? self::FAILURE : self::SUCCESS;
    }
}
