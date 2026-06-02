<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Contracts\MessageStoreInterface;
use Bschmitt\Amqp\Core\Amqp;
use Illuminate\Console\Command;

/**
 * Re-publish previously recorded messages from the {@see MessageStoreInterface}.
 *
 *   php artisan amqp:replay --routing=orders.created --limit=10
 *   php artisan amqp:replay --id=msg_42_xxx
 *   php artisan amqp:replay --correlation=corr_abc --target=orders.recovery
 *   php artisan amqp:replay --routing=orders.created --since=600 --dry-run
 *
 * Complements `amqp:dlq replay` (which drains the broker DLQ). This command
 * works against whatever store you've bound, including the in-memory store
 * used by tests and the durable Eloquent/Redis implementations users wire in
 * for production.
 */
class AmqpReplayCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:replay
                            {--id=* : Replay specific message ids}
                            {--routing= : Filter by source routing key}
                            {--direction=published : Filter by direction (default: published)}
                            {--correlation= : Filter by correlation id}
                            {--since= : Only entries recorded in the last N seconds}
                            {--limit=20 : Max entries to replay}
                            {--target= : Override the routing key on the re-publish}
                            {--exchange= : Exchange to publish to}
                            {--connection= : Override connection key}
                            {--dry-run : Show what would be replayed without publishing}
                            {--json : Output a machine-readable summary}';

    /** @var string */
    protected $description = 'Re-publish messages from the MessageStore by id / routing / correlation';

    /**
     * @param Amqp                  $amqp
     * @param MessageStoreInterface $store
     * @return int
     */
    public function handle(Amqp $amqp, MessageStoreInterface $store): int
    {
        $entries = $this->collect($store);
        if ($entries === []) {
            return $this->emit([
                'matched' => 0,
                'replayed' => 0,
                'dry_run' => (bool) $this->option('dry-run'),
            ], 'No matching messages to replay.');
        }

        $target = (string) ($this->option('target') ?: '');
        $exchange = (string) ($this->option('exchange') ?: '');
        $connection = (string) ($this->option('connection') ?: '');
        $dryRun = (bool) $this->option('dry-run');

        $publishProperties = [];
        if ($connection !== '') {
            $publishProperties['use'] = $connection;
        }
        if ($exchange !== '') {
            $publishProperties['exchange'] = $exchange;
        }

        $replayed = 0;
        $failures = [];

        foreach ($entries as $entry) {
            $routing = $target !== ''
                ? $target
                : (string) ($entry['routing'] ?? '');

            if ($routing === '') {
                $failures[] = [
                    'id' => isset($entry['id']) ? (string) $entry['id'] : null,
                    'error' => 'no routing key on entry and no --target provided',
                ];
                continue;
            }

            $properties = $publishProperties;
            if (!empty($entry['headers']) && is_array($entry['headers'])) {
                $properties['application_headers'] = $entry['headers'];
            }
            if (!empty($entry['properties']) && is_array($entry['properties'])) {
                if (!empty($entry['properties']['content_type'])) {
                    $properties['content_type'] = $entry['properties']['content_type'];
                }
                if (!empty($entry['properties']['correlation_id'])) {
                    $properties['correlation_id'] = $entry['properties']['correlation_id'];
                }
            }

            if ($dryRun) {
                $replayed++;
                continue;
            }

            try {
                $amqp->publish($routing, (string) ($entry['body'] ?? ''), $properties);
                $replayed++;
            } catch (\Throwable $e) {
                $failures[] = [
                    'id' => isset($entry['id']) ? (string) $entry['id'] : null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $payload = [
            'matched' => count($entries),
            'replayed' => $replayed,
            'failed' => count($failures),
            'failures' => $failures,
            'dry_run' => $dryRun,
            'target' => $target !== '' ? $target : null,
            'exchange' => $exchange !== '' ? $exchange : null,
        ];

        $message = $dryRun
            ? sprintf('Dry run: %d entries would be replayed.', $replayed)
            : sprintf('Replayed %d of %d matched entries.', $replayed, count($entries));

        return $this->emit($payload, $message, $failures === [] ? self::SUCCESS : self::FAILURE);
    }

    /**
     * @param MessageStoreInterface $store
     * @return array<int, array<string, mixed>>
     */
    protected function collect(MessageStoreInterface $store): array
    {
        $ids = (array) $this->option('id');
        if ($ids !== []) {
            $collected = [];
            foreach ($ids as $id) {
                $entry = $store->find((string) $id);
                if ($entry !== null) {
                    $collected[] = $entry;
                }
            }
            return $collected;
        }

        $direction = (string) ($this->option('direction') ?: 'published');
        $routing = (string) ($this->option('routing') ?: '');
        $correlation = (string) ($this->option('correlation') ?: '');
        $since = (int) ($this->option('since') ?: 0);
        $cutoff = $since > 0 ? microtime(true) - $since : null;
        $limit = max(1, (int) $this->option('limit'));

        $matched = [];
        foreach ($store->all() as $entry) {
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
            if (count($matched) >= $limit) {
                break;
            }
        }

        return $matched;
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
     * @param array<string, mixed> $payload
     * @param string               $humanMessage
     * @param int                  $exit
     * @return int
     */
    protected function emit(array $payload, string $humanMessage, int $exit = self::SUCCESS): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($humanMessage);
            if (!empty($payload['failures'])) {
                foreach ((array) $payload['failures'] as $fail) {
                    $this->error(sprintf(
                        '  %s: %s',
                        isset($fail['id']) ? (string) $fail['id'] : '?',
                        isset($fail['error']) ? (string) $fail['error'] : 'unknown error'
                    ));
                }
            }
        }

        return $exit;
    }
}
