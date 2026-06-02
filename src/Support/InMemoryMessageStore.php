<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\MessageStoreInterface;

/**
 * Process-local, array-backed {@see MessageStoreInterface}.
 *
 * Suitable for tests, single-worker scripts, and short-lived CLI runs.
 * NOT a substitute for durable storage in production — wire an Eloquent
 * or Redis-backed implementation behind the same interface there.
 */
class InMemoryMessageStore implements MessageStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    protected $entries = [];

    /** @var int */
    protected $sequence = 0;

    /**
     * @inheritDoc
     */
    public function append(string $direction, string $routing, string $body, array $properties = [], array $headers = []): string
    {
        $this->sequence++;
        $id = sprintf('msg_%d_%s', $this->sequence, uniqid('', true));

        $this->entries[] = [
            'id' => $id,
            'direction' => $direction,
            'routing' => $routing,
            'body' => $body,
            'properties' => $properties,
            'headers' => $headers,
            'recorded_at' => microtime(true),
        ];

        return $id;
    }

    /**
     * @inheritDoc
     */
    public function find(string $id): ?array
    {
        foreach ($this->entries as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function all(array $filter = [], ?int $limit = null): array
    {
        $matched = [];
        foreach ($this->entries as $entry) {
            if (!$this->matches($entry, $filter)) {
                continue;
            }
            $matched[] = $entry;
            if ($limit !== null && count($matched) >= $limit) {
                break;
            }
        }

        return $matched;
    }

    /**
     * @inheritDoc
     */
    public function count(array $filter = []): int
    {
        if ($filter === []) {
            return count($this->entries);
        }

        $total = 0;
        foreach ($this->entries as $entry) {
            if ($this->matches($entry, $filter)) {
                $total++;
            }
        }

        return $total;
    }

    /**
     * @inheritDoc
     */
    public function purge(): void
    {
        $this->entries = [];
        $this->sequence = 0;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $filter
     * @return bool
     */
    protected function matches(array $entry, array $filter): bool
    {
        foreach ($filter as $key => $value) {
            if (!array_key_exists($key, $entry) || $entry[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
