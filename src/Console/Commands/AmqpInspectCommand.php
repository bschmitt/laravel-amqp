<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Core\Amqp;
use Illuminate\Console\Command;

/**
 * Live queue inspector. Refreshes queue statistics on an interval and renders
 * a compact table in the terminal, like `watch -n 2 amqp:monitor` but with a
 * fixed-rate loop and a cooperative max-iteration cap so it's CI-friendly.
 *
 *   php artisan amqp:inspect orders orders.dlq
 *   php artisan amqp:inspect orders --interval=2 --iterations=5
 *   php artisan amqp:inspect orders --json --iterations=1
 *
 * Uses {@see Amqp::queueMetrics()}, which reads from the RabbitMQ Management
 * API. The command stops automatically after `--iterations` refreshes so it
 * can be wired into a cron / health-check without hanging.
 */
class AmqpInspectCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:inspect
                            {queues* : One or more queue names to watch}
                            {--vhost= : Vhost override}
                            {--interval=2 : Seconds between refreshes}
                            {--iterations=0 : Stop after N refreshes (0 = run until interrupted)}
                            {--json : Render snapshots as JSON instead of tables}
                            {--connection= : Override connection key}';

    /** @var string */
    protected $description = 'Live queue inspector (continuously refreshing snapshot of queue metrics)';

    /**
     * @param Amqp $amqp
     * @return int
     */
    public function handle(Amqp $amqp): int
    {
        $queues = (array) $this->argument('queues');
        if ($queues === []) {
            $this->error('At least one queue name is required.');
            return self::INVALID;
        }

        $vhost = (string) ($this->option('vhost') ?: '');
        $vhost = $vhost === '' ? null : $vhost;
        $interval = max(0.0, (float) $this->option('interval'));
        $maxIterations = max(0, (int) $this->option('iterations'));
        $json = (bool) $this->option('json');
        $connection = (string) ($this->option('connection') ?: '');

        $properties = [];
        if ($connection !== '') {
            $properties['use'] = $connection;
        }

        $iteration = 0;
        while (true) {
            $iteration++;
            $snapshot = $this->snapshot($amqp, $queues, $vhost, $properties);

            if ($json) {
                $this->line((string) json_encode([
                    'iteration' => $iteration,
                    'generated_at' => date('c'),
                    'queues' => $snapshot,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->renderHumanTable($iteration, $snapshot);
            }

            if ($maxIterations > 0 && $iteration >= $maxIterations) {
                return self::SUCCESS;
            }

            if ($interval <= 0.0) {
                return self::SUCCESS;
            }

            usleep((int) ($interval * 1000000));
        }
    }

    /**
     * @param Amqp                  $amqp
     * @param array<int, string>    $queues
     * @param string|null           $vhost
     * @param array<string, mixed>  $properties
     * @return array<int, array<string, mixed>>
     */
    protected function snapshot(Amqp $amqp, array $queues, ?string $vhost, array $properties): array
    {
        $rows = [];
        foreach ($queues as $name) {
            try {
                $metrics = $amqp->queueMetrics((string) $name, $vhost, $properties);
                $rows[] = $metrics->toArray();
            } catch (\Throwable $e) {
                $rows[] = [
                    'name' => (string) $name,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $rows;
    }

    /**
     * @param int                                $iteration
     * @param array<int, array<string, mixed>>   $rows
     * @return void
     */
    protected function renderHumanTable(int $iteration, array $rows): void
    {
        $this->line(sprintf('amqp:inspect #%d @ %s', $iteration, date('H:i:s')));
        $table = [];
        foreach ($rows as $row) {
            if (isset($row['error'])) {
                $table[] = [
                    (string) ($row['name'] ?? '?'),
                    'ERR',
                    '',
                    '',
                    '',
                    '',
                    (string) $row['error'],
                ];
                continue;
            }
            $table[] = [
                (string) ($row['name'] ?? '?'),
                (string) ($row['messages'] ?? '?'),
                (string) ($row['messages_ready'] ?? '?'),
                (string) ($row['messages_unacknowledged'] ?? '?'),
                (string) ($row['consumers'] ?? '?'),
                $this->fmtRate($row['publish_rate'] ?? null) . ' / ' . $this->fmtRate($row['deliver_rate'] ?? null),
                $this->fmtLag($row),
            ];
        }
        $this->table(
            ['queue', 'total', 'ready', 'unacked', 'consumers', 'pub/del rate', 'lag'],
            $table
        );
        $this->line('');
    }

    /**
     * @param float|null $rate
     * @return string
     */
    protected function fmtRate($rate): string
    {
        if ($rate === null) {
            return '–';
        }
        return number_format((float) $rate, 1) . '/s';
    }

    /**
     * @param array<string, mixed> $row
     * @return string
     */
    protected function fmtLag(array $row): string
    {
        $lag = isset($row['lag']) ? (int) $row['lag'] : 0;
        $secs = isset($row['lag_seconds']) ? $row['lag_seconds'] : null;
        if ($secs === null || !is_numeric($secs)) {
            return $lag . ' msg';
        }
        return $lag . ' msg / ' . number_format((float) $secs, 1) . 's';
    }
}
