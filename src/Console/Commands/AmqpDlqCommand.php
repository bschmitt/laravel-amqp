<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Core\Amqp;
use Illuminate\Console\Command;

/**
 * Inspect, replay, or purge a dead-letter queue.
 *
 *   php artisan amqp:dlq inspect orders.dlq
 *   php artisan amqp:dlq peek    orders.dlq --limit=20
 *   php artisan amqp:dlq summary orders.dlq --limit=200
 *   php artisan amqp:dlq replay  orders.dlq --target=orders --limit=100
 *   php artisan amqp:dlq purge   orders.dlq --force
 *
 * Backed by {@see \Bschmitt\Amqp\Support\DeadLetterManager}. `peek` is
 * non-destructive; `replay` and `purge` mutate broker state.
 */
class AmqpDlqCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:dlq
                            {action : One of: inspect|peek|summary|replay|purge}
                            {queue : Dead-letter queue name}
                            {--target= : Target queue for the replay action}
                            {--limit=10 : Sample/replay size}
                            {--exchange= : Exchange to publish to on replay}
                            {--force : Skip confirmation for purge}
                            {--json : Output the result as JSON}
                            {--connection= : Override connection key}';

    /** @var string */
    protected $description = 'Inspect, replay, or purge a dead-letter queue';

    /**
     * @param Amqp $amqp
     * @return int
     */
    public function handle(Amqp $amqp): int
    {
        $action = strtolower((string) $this->argument('action'));
        $queue = (string) $this->argument('queue');
        $limit = max(1, (int) $this->option('limit'));

        $properties = [];
        if ($connection = $this->option('connection')) {
            $properties['use'] = $connection;
        }

        $manager = $amqp->deadLetters()->for($queue);
        if ($properties !== []) {
            $manager->withProperties($properties);
        }

        try {
            switch ($action) {
                case 'inspect':
                    return $this->output(['queue' => $queue, 'messages' => $manager->count()]);

                case 'peek':
                    $messages = $manager->peek($limit);
                    return $this->output([
                        'queue' => $queue,
                        'sampled' => count($messages),
                        'messages' => $messages,
                    ]);

                case 'summary':
                    return $this->output(array_merge(['queue' => $queue], $manager->summarize($limit)));

                case 'replay':
                    $target = (string) $this->option('target');
                    if ($target === '') {
                        $this->error('--target is required for the replay action');
                        return self::FAILURE;
                    }
                    $exchange = (string) ($this->option('exchange') ?? '');
                    $count = $manager->replayTo($target, $limit, $exchange);
                    return $this->output([
                        'queue' => $queue,
                        'target' => $target,
                        'replayed' => $count,
                    ]);

                case 'purge':
                    if (!$this->option('force') && !$this->confirm("Purge all messages from {$queue}?", false)) {
                        $this->warn('Aborted.');
                        return self::SUCCESS;
                    }
                    $count = $manager->purge();
                    return $this->output(['queue' => $queue, 'purged' => $count]);

                default:
                    $this->error("Unknown action [{$action}]. Use inspect|peek|summary|replay|purge.");
                    return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('DLQ command failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return int
     */
    protected function output(array $payload): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $this->line($key . ':');
                $this->line('  ' . json_encode($value, JSON_UNESCAPED_SLASHES));
            } else {
                $this->line(sprintf('%s: %s', $key, is_scalar($value) ? (string) $value : json_encode($value)));
            }
        }

        return self::SUCCESS;
    }
}
