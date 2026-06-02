<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Contracts\MessageStoreInterface;
use Illuminate\Console\Command;

/**
 * Telescope-style explorer for the {@see MessageStoreInterface}.
 *
 * Two modes:
 *
 *   php artisan amqp:explore                              # paginated list
 *   php artisan amqp:explore --id=msg_42_...              # single message
 *   php artisan amqp:explore --direction=published        # filter
 *   php artisan amqp:explore --routing=orders.created     # filter
 *   php artisan amqp:explore --correlation=corr_abc       # filter
 *   php artisan amqp:explore --since=600                  # last N seconds
 *   php artisan amqp:explore --json                       # machine output
 *
 * Combined with `Amqp::setMessageStore(...)` (durable Eloquent / Redis store)
 * this becomes a queryable audit log without needing a separate UI.
 */
class AmqpExploreCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:explore
                            {--id= : Show a single entry by id}
                            {--direction= : Filter by published|consumed}
                            {--routing= : Filter by routing key}
                            {--correlation= : Filter by correlation id}
                            {--since= : Only entries recorded in the last N seconds}
                            {--limit=20 : Max entries to render}
                            {--body : Include the full body in the output}
                            {--json : Render entries as JSON}';

    /** @var string */
    protected $description = 'Inspect entries recorded in the MessageStore (audit log explorer)';

    /**
     * @param MessageStoreInterface $store
     * @return int
     */
    public function handle(MessageStoreInterface $store): int
    {
        $id = (string) ($this->option('id') ?: '');
        if ($id !== '') {
            return $this->showOne($store, $id);
        }

        $entries = $this->filterAndSort($store->all());
        $limit = max(1, (int) $this->option('limit'));
        $entries = array_slice($entries, 0, $limit);

        if ($entries === []) {
            $this->warn('No messages matched the given filters.');
            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $payload = array_map(function ($entry) {
                return $this->jsonShape($entry, (bool) $this->option('body'));
            }, $entries);
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->renderTable($entries);

        if ($this->option('body')) {
            foreach ($entries as $entry) {
                $this->line('');
                $this->line(sprintf('── %s ──', isset($entry['id']) ? (string) $entry['id'] : '?'));
                $this->line($this->prettyBody(isset($entry['body']) ? (string) $entry['body'] : ''));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param MessageStoreInterface $store
     * @param string                $id
     * @return int
     */
    protected function showOne(MessageStoreInterface $store, string $id): int
    {
        $entry = $store->find($id);
        if ($entry === null) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => 'not_found', 'id' => $id]));
            } else {
                $this->error("Message [{$id}] not found in the store.");
            }
            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->line(sprintf('id          : %s', $id));
        $this->line(sprintf('direction   : %s', isset($entry['direction']) ? (string) $entry['direction'] : '?'));
        $this->line(sprintf('routing     : %s', isset($entry['routing']) ? (string) $entry['routing'] : '?'));
        if (isset($entry['recorded_at'])) {
            $this->line(sprintf('recorded_at : %s', date('c', (int) $entry['recorded_at'])));
        }
        $this->line('');
        $this->line('-- body --');
        $this->line($this->prettyBody(isset($entry['body']) ? (string) $entry['body'] : ''));

        if (!empty($entry['properties'])) {
            $this->line('');
            $this->line('-- properties --');
            $this->line((string) json_encode($entry['properties'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        if (!empty($entry['headers'])) {
            $this->line('');
            $this->line('-- headers --');
            $this->line((string) json_encode($entry['headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    protected function filterAndSort(array $entries): array
    {
        $direction = (string) ($this->option('direction') ?: '');
        $routing = (string) ($this->option('routing') ?: '');
        $correlation = (string) ($this->option('correlation') ?: '');
        $since = (int) ($this->option('since') ?: 0);
        $cutoff = $since > 0 ? microtime(true) - $since : null;

        $matched = [];
        foreach ($entries as $entry) {
            if ($direction !== '' && (string) ($entry['direction'] ?? '') !== $direction) {
                continue;
            }
            if ($routing !== '' && (string) ($entry['routing'] ?? '') !== $routing) {
                continue;
            }
            if ($correlation !== '' && $this->correlationOf($entry) !== $correlation) {
                continue;
            }
            if ($cutoff !== null && (float) ($entry['recorded_at'] ?? 0) < $cutoff) {
                continue;
            }
            $matched[] = $entry;
        }

        usort($matched, static function ($a, $b) {
            $ta = isset($a['recorded_at']) ? (float) $a['recorded_at'] : 0.0;
            $tb = isset($b['recorded_at']) ? (float) $b['recorded_at'] : 0.0;
            if ($ta === $tb) {
                return 0;
            }
            return $ta < $tb ? 1 : -1;
        });

        return $matched;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return void
     */
    protected function renderTable(array $entries): void
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                isset($entry['id']) ? (string) $entry['id'] : '',
                isset($entry['direction']) ? (string) $entry['direction'] : '',
                isset($entry['routing']) ? (string) $entry['routing'] : '',
                $this->correlationOf($entry) ?? '',
                isset($entry['recorded_at']) ? date('H:i:s', (int) $entry['recorded_at']) : '',
                $this->bodyPreview(isset($entry['body']) ? (string) $entry['body'] : ''),
            ];
        }

        $this->table(['id', 'direction', 'routing', 'correlation', 'time', 'body'], $rows);
    }

    /**
     * @param array<string, mixed> $entry
     * @return string|null
     */
    protected function correlationOf(array $entry): ?string
    {
        $props = isset($entry['properties']) && is_array($entry['properties']) ? $entry['properties'] : [];
        if (!empty($props['correlation_id'])) {
            return (string) $props['correlation_id'];
        }
        $headers = isset($entry['headers']) && is_array($entry['headers']) ? $entry['headers'] : [];
        if (!empty($headers['x-correlation-id'])) {
            return (string) $headers['x-correlation-id'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @param bool                 $includeBody
     * @return array<string, mixed>
     */
    protected function jsonShape(array $entry, bool $includeBody): array
    {
        $row = [
            'id' => isset($entry['id']) ? (string) $entry['id'] : null,
            'direction' => isset($entry['direction']) ? (string) $entry['direction'] : null,
            'routing' => isset($entry['routing']) ? (string) $entry['routing'] : null,
            'recorded_at' => isset($entry['recorded_at']) ? (float) $entry['recorded_at'] : null,
            'correlation' => $this->correlationOf($entry),
        ];
        if ($includeBody) {
            $row['body'] = isset($entry['body']) ? (string) $entry['body'] : null;
            $row['properties'] = isset($entry['properties']) ? $entry['properties'] : [];
            $row['headers'] = isset($entry['headers']) ? $entry['headers'] : [];
        }

        return $row;
    }

    /**
     * @param string $body
     * @return string
     */
    protected function bodyPreview(string $body): string
    {
        $body = preg_replace('/\s+/', ' ', $body) ?? $body;
        if (strlen($body) <= 60) {
            return $body;
        }
        return substr($body, 0, 57) . '...';
    }

    /**
     * @param string $body
     * @return string
     */
    protected function prettyBody(string $body): string
    {
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return $body;
    }
}
