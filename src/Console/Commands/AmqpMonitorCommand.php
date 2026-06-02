<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Core\Amqp;
use Illuminate\Console\Command;

/**
 * Print a snapshot of the AMQP monitoring dashboard to the console.
 *
 *   php artisan amqp:monitor --queue=orders --queue=orders.dlq
 *   php artisan amqp:monitor --queue=orders --json
 *
 * Useful both as an operations tool and as the canonical way to verify the
 * package's in-process metrics + Management API integration without a UI.
 */
class AmqpMonitorCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:monitor
                            {--queue=* : Queue(s) to inspect (repeatable)}
                            {--json : Output the raw snapshot as JSON}
                            {--connection= : Override connection key}';

    /** @var string */
    protected $description = 'Show a snapshot of AMQP metrics and queue stats';

    /**
     * @param Amqp $amqp
     * @return int
     */
    public function handle(Amqp $amqp): int
    {
        $queues = (array) $this->option('queue');
        $properties = [];
        if ($connection = $this->option('connection')) {
            $properties['use'] = $connection;
        }

        $snapshot = $amqp->dashboard($queues, $properties)->snapshot();

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

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

        if ($snapshot['queues'] !== []) {
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
                ];
            }

            $this->table(
                ['Queue', 'Total', 'Ready', 'Unacked', 'Consumers', 'Publish/s', 'Deliver/s'],
                $rows
            );
        }

        if (isset($snapshot['overview']['error'])) {
            $this->warn('Overview unavailable: ' . $snapshot['overview']['error']);
        }

        return self::SUCCESS;
    }
}
