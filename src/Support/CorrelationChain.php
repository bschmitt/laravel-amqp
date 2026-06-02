<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\MessageStoreInterface;

/**
 * Reconstructs the causation tree of a single correlation_id from a
 * {@see MessageStoreInterface}.
 *
 * Every published or consumed message that flows through the package carries
 * two identifiers:
 *
 *   - `correlation_id` — shared by every message in a single business flow
 *   - `x-causation-id` (header) — id of the message that *caused* this one
 *
 * The chain helper walks a message store, groups by `correlation_id`, and
 * stitches the entries into:
 *
 *   - a **flat list** ordered by `recorded_at`, for tabular display
 *   - a **forest** (one tree per root) of `{ entry, children }` nodes, ready
 *     to be ASCII-rendered or JSON-serialised
 *
 * No UI library is shipped — this is the data layer the `amqp:trace`
 * Artisan command (and any future Pulse/Telescope panels) build on top of.
 */
class CorrelationChain
{
    /** @var MessageStoreInterface */
    protected $store;

    /**
     * @param MessageStoreInterface $store
     */
    public function __construct(MessageStoreInterface $store)
    {
        $this->store = $store;
    }

    /**
     * Every entry tagged with the given correlation id, oldest first.
     *
     * @param string $correlationId
     * @return array<int, array<string, mixed>>
     */
    public function entriesFor(string $correlationId): array
    {
        $matches = [];
        foreach ($this->store->all() as $entry) {
            if ($this->correlationIdOf($entry) === $correlationId) {
                $matches[] = $entry;
            }
        }

        usort($matches, static function ($a, $b) {
            $ta = isset($a['recorded_at']) ? (float) $a['recorded_at'] : 0.0;
            $tb = isset($b['recorded_at']) ? (float) $b['recorded_at'] : 0.0;
            if ($ta === $tb) {
                return 0;
            }

            return $ta < $tb ? -1 : 1;
        });

        return $matches;
    }

    /**
     * Build the causation forest for a correlation id.
     *
     * Each node is shaped as `['entry' => …, 'children' => [...]]`. Entries
     * whose causation_id is unknown to the store appear as roots; orphaned
     * children (causation_id references a message we never recorded) are
     * promoted to roots too so nothing is lost.
     *
     * @param string $correlationId
     * @return array<int, array<string, mixed>>
     */
    public function tree(string $correlationId): array
    {
        $entries = $this->entriesFor($correlationId);
        if ($entries === []) {
            return [];
        }

        $byMessageId = [];
        $nodes = [];
        foreach ($entries as $index => $entry) {
            $mid = $this->messageIdOf($entry);
            if ($mid !== null) {
                $byMessageId[$mid] = $index;
            }
            $nodes[$index] = [
                'entry' => $entry,
                'children' => [],
            ];
        }

        $roots = [];
        foreach ($entries as $index => $entry) {
            $causation = $this->causationIdOf($entry);
            if ($causation !== null && isset($byMessageId[$causation])) {
                $parentIndex = $byMessageId[$causation];
                $nodes[$parentIndex]['children'][] = &$nodes[$index];
                continue;
            }
            $roots[] = &$nodes[$index];
        }

        return $roots;
    }

    /**
     * Render a causation forest as an ASCII tree.
     *
     * @param array<int, array<string, mixed>> $forest Output of {@see tree()}.
     * @return string
     */
    public function render(array $forest): string
    {
        $lines = [];
        foreach ($forest as $i => $node) {
            $this->renderNode($node, '', $i === count($forest) - 1, $lines, true);
        }

        return implode("\n", $lines);
    }

    /**
     * Summarise everything we know about a correlation id.
     *
     * @param string $correlationId
     * @return array<string, mixed>
     */
    public function summarize(string $correlationId): array
    {
        $entries = $this->entriesFor($correlationId);

        $published = 0;
        $consumed = 0;
        $routings = [];
        $firstAt = null;
        $lastAt = null;
        foreach ($entries as $entry) {
            $direction = isset($entry['direction']) ? (string) $entry['direction'] : '';
            if ($direction === 'published') {
                $published++;
            } elseif ($direction === 'consumed') {
                $consumed++;
            }
            $routing = isset($entry['routing']) ? (string) $entry['routing'] : '';
            if ($routing !== '') {
                $routings[$routing] = isset($routings[$routing]) ? $routings[$routing] + 1 : 1;
            }
            $recorded = isset($entry['recorded_at']) ? (float) $entry['recorded_at'] : null;
            if ($recorded !== null) {
                if ($firstAt === null || $recorded < $firstAt) {
                    $firstAt = $recorded;
                }
                if ($lastAt === null || $recorded > $lastAt) {
                    $lastAt = $recorded;
                }
            }
        }

        $durationMs = ($firstAt !== null && $lastAt !== null)
            ? max(0.0, ($lastAt - $firstAt) * 1000.0)
            : 0.0;

        return [
            'correlation_id' => $correlationId,
            'total' => count($entries),
            'published' => $published,
            'consumed' => $consumed,
            'routings' => $routings,
            'first_at' => $firstAt,
            'last_at' => $lastAt,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * @param array<string, mixed>            $node
     * @param string                          $prefix
     * @param bool                            $isLast
     * @param array<int, string>              $lines  Accumulator (mutated).
     * @param bool                            $isRoot
     * @return void
     */
    protected function renderNode(array $node, string $prefix, bool $isLast, array &$lines, bool $isRoot): void
    {
        $entry = isset($node['entry']) && is_array($node['entry']) ? $node['entry'] : [];
        $label = $this->labelFor($entry);

        if ($isRoot) {
            $lines[] = $label;
            $childPrefix = '';
        } else {
            $connector = $isLast ? '└── ' : '├── ';
            $lines[] = $prefix . $connector . $label;
            $childPrefix = $prefix . ($isLast ? '    ' : '│   ');
        }

        $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];
        $count = count($children);
        $idx = 0;
        foreach ($children as $child) {
            $this->renderNode($child, $childPrefix, $idx === $count - 1, $lines, false);
            $idx++;
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @return string
     */
    protected function labelFor(array $entry): string
    {
        $direction = isset($entry['direction']) ? (string) $entry['direction'] : '?';
        $routing = isset($entry['routing']) ? (string) $entry['routing'] : '?';
        $mid = $this->messageIdOf($entry);
        $mid = $mid !== null ? $mid : (isset($entry['id']) ? (string) $entry['id'] : '?');

        $arrow = $direction === 'published' ? '>>' : ($direction === 'consumed' ? '<<' : '--');

        return sprintf('[%s] %s %s (msg=%s)', $direction, $arrow, $routing, $mid);
    }

    /**
     * @param array<string, mixed> $entry
     * @return string|null
     */
    protected function correlationIdOf(array $entry): ?string
    {
        $props = isset($entry['properties']) && is_array($entry['properties']) ? $entry['properties'] : [];
        if (!empty($props['correlation_id'])) {
            return (string) $props['correlation_id'];
        }
        $headers = isset($entry['headers']) && is_array($entry['headers']) ? $entry['headers'] : [];
        if (!empty($headers[CorrelationContext::HEADER])) {
            return (string) $headers[CorrelationContext::HEADER];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @return string|null
     */
    protected function messageIdOf(array $entry): ?string
    {
        $props = isset($entry['properties']) && is_array($entry['properties']) ? $entry['properties'] : [];
        if (!empty($props['message_id'])) {
            return (string) $props['message_id'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @return string|null
     */
    protected function causationIdOf(array $entry): ?string
    {
        $headers = isset($entry['headers']) && is_array($entry['headers']) ? $entry['headers'] : [];
        if (!empty($headers[CorrelationContext::CAUSATION_HEADER])) {
            return (string) $headers[CorrelationContext::CAUSATION_HEADER];
        }

        return null;
    }
}
