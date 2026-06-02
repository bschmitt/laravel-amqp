<?php

namespace Bschmitt\Amqp\Support;

/**
 * Pick + fail-over between multiple region-scoped AMQP connection keys.
 *
 * Most production deployments configure one connection per region:
 *
 *   'properties' => [
 *       'production'      => [...],
 *       'production-eu'   => [...],
 *       'production-apac' => [...],
 *   ],
 *
 * This helper:
 *  - picks a preferred connection (locality first, falling back to
 *    user-supplied order or {@see LaravelCloud::region()});
 *  - records health per connection so callers can blacklist a broken region
 *    for a cool-down window;
 *  - exposes `each()` for callers that want to broadcast — e.g. publishing
 *    a fan-out announcement to every region.
 *
 * Stateful but tiny: hold a single instance per process via the service
 * container.
 */
class MultiRegionConnection
{
    /** @var array<int, string> Configured region keys, in priority order. */
    protected $regions = [];

    /** @var string|null Local region preference. */
    protected $primaryRegion;

    /** @var array<string, float> Connection -> retry-after timestamp. */
    protected $blacklist = [];

    /** @var int Backoff in seconds after marking a region failed. */
    protected $cooldownSeconds;

    /** @var string|null */
    protected $regionTag;

    /**
     * @param array<int, string> $regions       Ordered region keys.
     * @param string|null        $primary       Preferred region (resolves locality).
     * @param int                $cooldown      Seconds to skip a failed region.
     * @param string|null        $regionTag     Optional pre-resolved local region (e.g. AWS_REGION).
     */
    public function __construct(
        array $regions,
        ?string $primary = null,
        int $cooldown = 30,
        ?string $regionTag = null
    ) {
        $this->regions = array_values(array_filter(array_map('strval', $regions), function ($r) {
            return $r !== '';
        }));
        $this->primaryRegion = $primary;
        $this->cooldownSeconds = max(0, $cooldown);
        $this->regionTag = $regionTag !== null ? $regionTag : LaravelCloud::region();
    }

    /**
     * Pick the next healthy connection key. Returns null when every region is
     * still in cool-down.
     */
    public function pick(): ?string
    {
        $now = microtime(true);
        $ordered = $this->orderByLocality();

        foreach ($ordered as $region) {
            $available = $this->blacklist[$region] ?? 0.0;
            if ($available > $now) {
                continue;
            }
            return $region;
        }

        return null;
    }

    /**
     * Mark a region failed (cool-down before re-trying).
     */
    public function markFailed(string $region): void
    {
        $this->blacklist[$region] = microtime(true) + $this->cooldownSeconds;
    }

    /**
     * Mark a region healthy again, clearing any cool-down.
     */
    public function markHealthy(string $region): void
    {
        unset($this->blacklist[$region]);
    }

    /**
     * Iterate over every configured region in priority order, regardless of
     * cool-down state. Useful for fan-out publishing.
     *
     * @return array<int, string>
     */
    public function each(): array
    {
        return $this->orderByLocality();
    }

    /**
     * Run a callback with a healthy region, retrying through every available
     * region until one succeeds. The callback receives the chosen region key
     * and should throw on failure.
     *
     * @template T
     * @param callable $callback callable(string $region): T
     * @return mixed
     */
    public function withFailover(callable $callback)
    {
        $attempts = 0;
        $lastException = null;
        $ordered = $this->orderByLocality();

        foreach ($ordered as $region) {
            $now = microtime(true);
            if (isset($this->blacklist[$region]) && $this->blacklist[$region] > $now) {
                continue;
            }
            $attempts++;
            try {
                $result = call_user_func($callback, $region);
                $this->markHealthy($region);
                return $result;
            } catch (\Throwable $e) {
                $this->markFailed($region);
                $lastException = $e;
            }
        }

        if ($attempts === 0) {
            throw new \RuntimeException('All AMQP regions are currently in cool-down.');
        }

        throw $lastException !== null
            ? $lastException
            : new \RuntimeException('Every region failed but no exception was captured.');
    }

    /**
     * @return array<int, string>
     */
    public function regions(): array
    {
        return $this->regions;
    }

    /**
     * @return array<string, float>
     */
    public function blacklist(): array
    {
        return $this->blacklist;
    }

    public function primary(): ?string
    {
        return $this->primaryRegion;
    }

    /**
     * @return array<int, string>
     */
    protected function orderByLocality(): array
    {
        if ($this->regions === []) {
            return [];
        }

        $preferred = $this->primaryRegion ?? $this->matchByTag();
        if ($preferred === null || !in_array($preferred, $this->regions, true)) {
            return $this->regions;
        }

        $ordered = [$preferred];
        foreach ($this->regions as $region) {
            if ($region !== $preferred) {
                $ordered[] = $region;
            }
        }
        return $ordered;
    }

    /**
     * Best-effort match between the runtime region tag and a configured key
     * (e.g. `us-east-1` matches `production-us`, `production-us-east`,
     * `prod-us-east-1`).
     */
    protected function matchByTag(): ?string
    {
        if ($this->regionTag === null || $this->regionTag === '') {
            return null;
        }
        $needle = strtolower($this->regionTag);
        foreach ($this->regions as $region) {
            if (stripos($region, $needle) !== false) {
                return $region;
            }
        }
        // Loose match: split the tag by `-` and look for any chunk that lines up.
        foreach (preg_split('/[-_]/', $needle) ?: [] as $chunk) {
            if ($chunk === '') {
                continue;
            }
            foreach ($this->regions as $region) {
                if (stripos($region, $chunk) !== false) {
                    return $region;
                }
            }
        }
        return null;
    }
}
