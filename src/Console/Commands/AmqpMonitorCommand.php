<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Core\Amqp;
use Illuminate\Console\Command;

/**
 * Print a snapshot of the AMQP monitoring dashboard to the console.
 *
 *   php artisan amqp:monitor --queue=orders --queue=orders.dlq
 *   php artisan amqp:monitor --queue=orders --json
 *   php artisan amqp:monitor --queue=orders --lag-threshold=1000 --lag-seconds=60
 *   php artisan amqp:monitor --dlq=orders.dlq
 *   php artisan amqp:monitor --rpc
 *
 * Useful both as an operations tool and as the canonical way to verify the
 * package's in-process metrics + Management API integration without a UI.
 *
 * Exits with code 1 when any lag threshold is exceeded so the command can be
 * wired into cron / CI as a soft alarm.
 */
class AmqpMonitorCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:monitor
                            {--queue=* : Queue(s) to inspect (repeatable)}
                            {--dlq=* : Queue(s) to inspect as dead-letter queues (repeatable)}
                            {--rpc : Include RPC latency snapshot}
                            {--lag-threshold= : Fail (exit 1) when any queue has backlog above this size}
                            {--lag-seconds= : Fail (exit 1) when any queue lag time-to-drain exceeds this (seconds)}
                            {--lag-age= : Fail (exit 1) when head-of-queue age exceeds this (seconds)}
                            {--json : Output the raw snapshot as JSON}
                            {--connection= : Override connection key}';

    /** @var string */
    protected $description = 'Show a snapshot of AMQP metrics, queue stats, DLQs, and RPC latencies';

    /**
     * @param Amqp $amqp
     * @return int
     */
    public function handle(Amqp $amqp): int
    {
        $queues = (array) $this->option('queue');
        $dlqs = (array) $this->option('dlq');
        $properties = [];
        if ($connection = $this->option('connection')) {
            $properties['use'] = $connection;
        }

        $dashboard = $amqp->dashboard($queues, $properties);
        if ($dlqs !== []) {
            $dashboard->deadLetters($dlqs);
        }

        $lagBacklog = $this->intOption('lag-threshold');
        $lagSeconds = $this->floatOption('lag-seconds');
        $lagAge = $this->intOption('lag-age');
        if ($lagBacklog !== null || $lagSeconds !== null || $lagAge !== null) {
            $dashboard->lagThresholds($lagBacklog, $lagSeconds, $lagAge);
        }

        $snapshot = $dashboard->snapshot();

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return !empty($snapshot['lagging']) ? self::FAILURE : self::SUCCESS;
        }

        $this->renderHuman($snapshot);

        return !empty($snapshot['lagging']) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return void
     */
    protected function renderHuman(array $snapshot): void
    {
        $this->info('AMQP Dashboard @ ' . $snapshot['generated']);
        $this->newLine();

        $process = $snapshot['process'];
        $this->line(sprintf(
            ' Process: published=%d consumed=%d handled=%d failed=%d',
            $process['published'] ?? 0,
            $process['consumed'] ?? 0,
            $process['handled'] ?? 0,
            $process['failed'] ?? 0
        ));
        $this->newLine();

        if (!empty($snapshot['queues'])) {
            $rows = [];
            foreach ($snapshot['queues'] as $name => $data) {
                $rows[] = [
                    $name,
                    $data['messages'] ?? '-',
                    $data['messages_ready'] ?? '-',
                    $data['messages_unacknowledged'] ?? '-',
                    $data['consumers'] ?? '-',
                    isset($data['publish_rate']) ? sprintf('%.2f', $data['publish_rate']) : '-',
                    isset($data['deliver_rate']) ? sprintf('%.2f', $data['deliver_rate']) : '-',
                    isset($data['lag']) ? $data['lag'] : '-',
                    isset($data['lag_seconds']) && $data['lag_seconds'] !== null
                        ? sprintf('%.1fs', $data['lag_seconds'])
                        : '-',
                    !empty($data['lagging']) ? 'YES' : 'no',
                ];
            }

            $this->table(
                ['Queue', 'Total', 'Ready', 'Unacked', 'Consumers', 'Pub/s', 'Del/s', 'Lag', 'ETA', 'Lagging?'],
                $rows
            );
        }

        if (!empty($snapshot['dead_letters'])) {
            $this->newLine();
            $this->info('Dead-letter queues:');
            foreach ($snapshot['dead_letters'] as $name => $dlq) {
                $messages = isset($dlq['messages']) ? $dlq['messages'] : '?';
                $this->line(sprintf(' - %s (messages=%s)', $name, $messages));
                $summary = isset($dlq['summary']) ? $dlq['summary'] : [];
                if (isset($summary['error'])) {
                    $this->warn('   summary unavailable: ' . $summary['error']);
                    continue;
                }
                if (!empty($summary['by_reason'])) {
                    $this->line('   by reason: ' . $this->compactCounts($summary['by_reason']));
                }
                if (!empty($summary['top_errors'])) {
                    $this->line('   top errors: ' . $this->compactCounts($summary['top_errors']));
                }
            }
        }

        if (!empty($snapshot['rpc'])) {
            $this->newLine();
            $this->info('RPC metrics:');
            $rows = [];
            foreach ($snapshot['rpc'] as $key => $row) {
                $rows[] = [
                    $key,
                    $row['count'] ?? 0,
                    $row['failed'] ?? 0,
                    sprintf('%.2f', $row['avg_ms'] ?? 0),
                    sprintf('%.2f', $row['p50_ms'] ?? 0),
                    sprintf('%.2f', $row['p95_ms'] ?? 0),
                    sprintf('%.2f', $row['p99_ms'] ?? 0),
                ];
            }
            $this->table(['Key', 'Count', 'Failed', 'Avg ms', 'p50 ms', 'p95 ms', 'p99 ms'], $rows);
        }

        if (!empty($snapshot['lagging'])) {
            $this->newLine();
            $this->error('Lagging queues: ' . implode(', ', $snapshot['lagging']));
        }

        if (isset($snapshot['overview']['error'])) {
            $this->warn('Overview unavailable: ' . $snapshot['overview']['error']);
        }
    }

    /**
     * @param array<string, int> $counts
     * @return string
     */
    protected function compactCounts(array $counts): string
    {
        $pairs = [];
        foreach ($counts as $k => $v) {
            $pairs[] = sprintf('%s=%d', $k, $v);
        }
        return implode(', ', $pairs);
    }

    /**
     * @param string $key
     * @return int|null
     */
    protected function intOption(string $key): ?int
    {
        $val = $this->option($key);
        return ($val === null || $val === '') ? null : (int) $val;
    }

    /**
     * @param string $key
     * @return float|null
     */
    protected function floatOption(string $key): ?float
    {
        $val = $this->option($key);
        return ($val === null || $val === '') ? null : (float) $val;
    }
}
