<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Contracts\MessageStoreInterface;
use Bschmitt\Amqp\Support\CorrelationChain;
use Illuminate\Console\Command;

/**
 * Visualise the causation chain of a correlation id.
 *
 *   php artisan amqp:trace corr_abc123
 *   php artisan amqp:trace corr_abc123 --json
 *   php artisan amqp:trace corr_abc123 --summary
 *
 * Reads from the bound {@see MessageStoreInterface}. Returns failure when the
 * store has no entries for the requested id so the command is CI-friendly.
 */
class AmqpTraceCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:trace
                            {correlation_id : The correlation id to visualise}
                            {--json : Output the chain as JSON}
                            {--summary : Show counts and timings only}
                            {--limit=0 : Cap on rendered entries (0 = no cap)}';

    /** @var string */
    protected $description = 'Render the causation tree for a correlation id from the MessageStore';

    /**
     * @param MessageStoreInterface $store
     * @return int
     */
    public function handle(MessageStoreInterface $store): int
    {
        $correlationId = (string) $this->argument('correlation_id');
        if ($correlationId === '') {
            $this->error('correlation_id is required.');
            return self::FAILURE;
        }

        $chain = new CorrelationChain($store);
        $entries = $chain->entriesFor($correlationId);

        if ($entries === []) {
            if ($this->option('json')) {
                $this->line((string) json_encode([
                    'correlation_id' => $correlationId,
                    'entries' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->warn("No messages recorded for correlation_id={$correlationId}.");
            }
            return self::FAILURE;
        }

        $summary = $chain->summarize($correlationId);
        $limit = max(0, (int) $this->option('limit'));

        if ($this->option('json')) {
            $tree = $chain->tree($correlationId);
            $payload = [
                'summary' => $summary,
                'entries' => $limit > 0 ? array_slice($entries, 0, $limit) : $entries,
                'tree' => $limit > 0 ? array_slice($tree, 0, $limit) : $tree,
            ];
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->renderHeader($summary);

        if ($this->option('summary')) {
            return self::SUCCESS;
        }

        $tree = $chain->tree($correlationId);
        if ($limit > 0) {
            $tree = array_slice($tree, 0, $limit);
        }
        $rendered = $chain->render($tree);
        if ($rendered !== '') {
            $this->line('');
            $this->line($rendered);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $summary
     * @return void
     */
    protected function renderHeader(array $summary): void
    {
        $this->line(sprintf(
            'correlation_id: %s',
            isset($summary['correlation_id']) ? (string) $summary['correlation_id'] : '?'
        ));
        $this->line(sprintf(
            'messages: %d (published=%d, consumed=%d)',
            (int) ($summary['total'] ?? 0),
            (int) ($summary['published'] ?? 0),
            (int) ($summary['consumed'] ?? 0)
        ));
        if (!empty($summary['duration_ms'])) {
            $this->line(sprintf('span: %.2f ms', (float) $summary['duration_ms']));
        }
        $routings = isset($summary['routings']) && is_array($summary['routings']) ? $summary['routings'] : [];
        if ($routings !== []) {
            $parts = [];
            foreach ($routings as $route => $count) {
                $parts[] = sprintf('%s(%d)', (string) $route, (int) $count);
            }
            $this->line('routings: ' . implode(', ', $parts));
        }
    }
}
