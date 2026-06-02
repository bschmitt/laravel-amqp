<?php

namespace Bschmitt\Amqp\Support;

/**
 * Structural diff of two AMQP message payloads.
 *
 * Designed for the `amqp:diff` Artisan command and any future UI plugged on
 * top of {@see \Bschmitt\Amqp\Contracts\MessageStoreInterface}. Operates in
 * three layers:
 *
 *   - **Payload** — JSON decodes both bodies when possible and walks them
 *     recursively, emitting `added` / `removed` / `changed` keys with full
 *     JSON-pointer paths (`/items/0/total`). Falls back to a line-diff for
 *     non-JSON bodies.
 *   - **Headers** — flat key-by-key comparison.
 *   - **Properties** — flat key-by-key comparison (AMQP basic properties such
 *     as `content_type`, `correlation_id`, `message_id`).
 *
 * Output is plain PHP arrays so callers can pretty-print, JSON-encode, or
 * render in any future explorer UI without touching this class.
 */
class MessageDiff
{
    /**
     * Compute a full diff between two message-store entries.
     *
     * Each entry is the shape produced by {@see \Bschmitt\Amqp\Contracts\MessageStoreInterface::find()}:
     * `['id', 'direction', 'routing', 'body', 'properties', 'headers', ...]`.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    public function diff(array $left, array $right): array
    {
        return [
            'left' => $this->headline($left),
            'right' => $this->headline($right),
            'body' => $this->diffBody(
                isset($left['body']) ? (string) $left['body'] : '',
                isset($right['body']) ? (string) $right['body'] : ''
            ),
            'headers' => $this->diffAssoc(
                isset($left['headers']) && is_array($left['headers']) ? $left['headers'] : [],
                isset($right['headers']) && is_array($right['headers']) ? $right['headers'] : []
            ),
            'properties' => $this->diffAssoc(
                isset($left['properties']) && is_array($left['properties']) ? $left['properties'] : [],
                isset($right['properties']) && is_array($right['properties']) ? $right['properties'] : []
            ),
        ];
    }

    /**
     * Diff two message bodies. Tries JSON first, falls back to a line diff.
     *
     * @param string $left
     * @param string $right
     * @return array<string, mixed>
     */
    public function diffBody(string $left, string $right): array
    {
        $leftJson = $this->tryJson($left);
        $rightJson = $this->tryJson($right);

        if ($leftJson['ok'] && $rightJson['ok']) {
            $changes = [];
            $this->walk($leftJson['value'], $rightJson['value'], '', $changes);
            return [
                'format' => 'json',
                'identical' => $changes === [],
                'changes' => $changes,
            ];
        }

        if ($left === $right) {
            return ['format' => 'text', 'identical' => true, 'changes' => []];
        }

        return [
            'format' => 'text',
            'identical' => false,
            'changes' => $this->lineDiff($left, $right),
        ];
    }

    /**
     * Diff two associative arrays (headers / properties).
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    public function diffAssoc(array $left, array $right): array
    {
        $added = [];
        $removed = [];
        $changed = [];

        foreach ($right as $key => $value) {
            if (!array_key_exists($key, $left)) {
                $added[(string) $key] = $value;
            } elseif (!$this->loose($left[$key], $value)) {
                $changed[(string) $key] = ['from' => $left[$key], 'to' => $value];
            }
        }
        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right)) {
                $removed[(string) $key] = $value;
            }
        }

        return [
            'identical' => $added === [] && $removed === [] && $changed === [],
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
        ];
    }

    /**
     * Render the diff as a human-friendly ASCII block.
     *
     * @param array<string, mixed> $diff Output of {@see diff()}.
     * @return string
     */
    public function render(array $diff): string
    {
        $lines = [];
        $left = isset($diff['left']) && is_array($diff['left']) ? $diff['left'] : [];
        $right = isset($diff['right']) && is_array($diff['right']) ? $diff['right'] : [];

        $lines[] = sprintf(
            'left : %s',
            $this->formatHeadline($left)
        );
        $lines[] = sprintf(
            'right: %s',
            $this->formatHeadline($right)
        );

        foreach (['body', 'headers', 'properties'] as $section) {
            $lines[] = '';
            $lines[] = '── ' . $section . ' ──';
            $block = isset($diff[$section]) && is_array($diff[$section]) ? $diff[$section] : [];
            if (!empty($block['identical'])) {
                $lines[] = '  (identical)';
                continue;
            }

            if ($section === 'body' && ($block['format'] ?? null) === 'text') {
                foreach ((array) ($block['changes'] ?? []) as $change) {
                    $sign = isset($change['op']) && $change['op'] === 'added' ? '+' : '-';
                    $lines[] = '  ' . $sign . ' ' . (isset($change['line']) ? (string) $change['line'] : '');
                }
                continue;
            }

            if ($section === 'body') {
                foreach ((array) ($block['changes'] ?? []) as $change) {
                    $lines[] = $this->renderJsonChange($change);
                }
                continue;
            }

            foreach ((array) ($block['added'] ?? []) as $key => $value) {
                $lines[] = sprintf('  + %s = %s', $key, $this->scalarize($value));
            }
            foreach ((array) ($block['removed'] ?? []) as $key => $value) {
                $lines[] = sprintf('  - %s = %s', $key, $this->scalarize($value));
            }
            foreach ((array) ($block['changed'] ?? []) as $key => $diffEntry) {
                $lines[] = sprintf(
                    '  ~ %s : %s -> %s',
                    $key,
                    $this->scalarize(is_array($diffEntry) ? ($diffEntry['from'] ?? null) : null),
                    $this->scalarize(is_array($diffEntry) ? ($diffEntry['to'] ?? null) : null)
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    protected function headline(array $entry): array
    {
        return [
            'id' => isset($entry['id']) ? (string) $entry['id'] : null,
            'direction' => isset($entry['direction']) ? (string) $entry['direction'] : null,
            'routing' => isset($entry['routing']) ? (string) $entry['routing'] : null,
            'recorded_at' => isset($entry['recorded_at']) ? (float) $entry['recorded_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $headline
     * @return string
     */
    protected function formatHeadline(array $headline): string
    {
        return sprintf(
            '%s [%s] %s',
            isset($headline['id']) ? (string) $headline['id'] : '?',
            isset($headline['direction']) ? (string) $headline['direction'] : '?',
            isset($headline['routing']) ? (string) $headline['routing'] : '?'
        );
    }

    /**
     * Recursively walk two JSON-decoded structures.
     *
     * @param mixed              $left
     * @param mixed              $right
     * @param string             $path
     * @param array<int, array<string, mixed>> $changes (mutated)
     * @return void
     */
    protected function walk($left, $right, string $path, array &$changes): void
    {
        if ($this->loose($left, $right)) {
            return;
        }

        if (!is_array($left) || !is_array($right)) {
            $changes[] = [
                'op' => 'changed',
                'path' => $path === '' ? '/' : $path,
                'from' => $left,
                'to' => $right,
            ];
            return;
        }

        $keys = array_unique(array_merge(array_keys($left), array_keys($right)));
        foreach ($keys as $key) {
            $childPath = $path . '/' . $key;
            $hasLeft = array_key_exists($key, $left);
            $hasRight = array_key_exists($key, $right);

            if (!$hasLeft) {
                $changes[] = ['op' => 'added', 'path' => $childPath, 'to' => $right[$key]];
                continue;
            }
            if (!$hasRight) {
                $changes[] = ['op' => 'removed', 'path' => $childPath, 'from' => $left[$key]];
                continue;
            }
            $this->walk($left[$key], $right[$key], $childPath, $changes);
        }
    }

    /**
     * @param string $body
     * @return array{ok: bool, value: mixed}
     */
    protected function tryJson(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'value' => null];
        }
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'value' => null];
        }

        return ['ok' => true, 'value' => $decoded];
    }

    /**
     * Cheap line-by-line diff for non-JSON bodies.
     *
     * @param string $left
     * @param string $right
     * @return array<int, array<string, mixed>>
     */
    protected function lineDiff(string $left, string $right): array
    {
        $leftLines = preg_split("/\r?\n/", $left) ?: [];
        $rightLines = preg_split("/\r?\n/", $right) ?: [];

        $changes = [];
        $max = max(count($leftLines), count($rightLines));
        for ($i = 0; $i < $max; $i++) {
            $l = $leftLines[$i] ?? null;
            $r = $rightLines[$i] ?? null;
            if ($l === $r) {
                continue;
            }
            if ($l !== null) {
                $changes[] = ['op' => 'removed', 'line' => $l];
            }
            if ($r !== null) {
                $changes[] = ['op' => 'added', 'line' => $r];
            }
        }

        return $changes;
    }

    /**
     * Loose equality that handles arrays via deep comparison.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    protected function loose($a, $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !$this->loose($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }

        return $a === $b;
    }

    /**
     * @param mixed $value
     * @return string
     */
    protected function scalarize($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        $encoded = json_encode($value);
        return $encoded === false ? '<unserializable>' : $encoded;
    }

    /**
     * @param array<string, mixed> $change
     * @return string
     */
    protected function renderJsonChange(array $change): string
    {
        $path = isset($change['path']) ? (string) $change['path'] : '/';
        $op = isset($change['op']) ? (string) $change['op'] : 'changed';

        if ($op === 'added') {
            return sprintf('  + %s = %s', $path, $this->scalarize($change['to'] ?? null));
        }
        if ($op === 'removed') {
            return sprintf('  - %s = %s', $path, $this->scalarize($change['from'] ?? null));
        }

        return sprintf(
            '  ~ %s : %s -> %s',
            $path,
            $this->scalarize($change['from'] ?? null),
            $this->scalarize($change['to'] ?? null)
        );
    }
}
