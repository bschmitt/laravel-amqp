<?php

namespace Bschmitt\Amqp\Contracts;

/**
 * Append-only log of messages produced or received by the application.
 *
 * Not a full event-store: there's no aggregate-stream concept, no concurrency
 * control, no projections. Just a durable record of every envelope that flew
 * past, so callers can replay traffic, build audit trails, or feed an external
 * event-sourcing engine.
 *
 * Implementations:
 *   - {@see \Bschmitt\Amqp\Support\InMemoryMessageStore} — array-backed, tests
 *   - Future: Eloquent / Redis / file-based stores.
 */
interface MessageStoreInterface
{
    /**
     * Append a new entry. Implementations MUST assign and return a stable id.
     *
     * @param string               $direction 'published' | 'consumed'
     * @param string               $routing   Routing key / queue name.
     * @param string               $body      Raw message body.
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $headers
     * @return string Stable id assigned to the entry.
     */
    public function append(string $direction, string $routing, string $body, array $properties = [], array $headers = []): string;

    /**
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array;

    /**
     * @param array<string, mixed> $filter Optional filter (`direction`, `routing`).
     * @param int|null             $limit
     * @return array<int, array<string, mixed>>
     */
    public function all(array $filter = [], ?int $limit = null): array;

    /**
     * Total number of entries (matching the optional filter).
     *
     * @param array<string, mixed> $filter
     * @return int
     */
    public function count(array $filter = []): int;

    /**
     * Remove every entry. Mostly for testing.
     *
     * @return void
     */
    public function purge(): void;
}
