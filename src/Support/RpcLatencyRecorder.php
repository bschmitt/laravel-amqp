<?php

namespace Bschmitt\Amqp\Support;

/**
 * In-process aggregator for RPC call latencies.
 *
 *   $rec = $amqp->rpcMetrics();
 *   $rec->record('UserService::getUser', 12.4);
 *   $rec->record('UserService::getUser', 8.1, true); // failed call
 *   $rec->snapshot(); // ['UserService::getUser' => ['count' => 2, 'p95_ms' => ...]]
 *
 * The recorder uses a fixed-bucket histogram (logarithmic-ish) so percentile
 * estimates are computed in constant time without depending on a t-digest
 * library. Bucket boundaries are tuned for typical AMQP-RPC latencies
 * (sub-millisecond to multi-second).
 *
 * The recorder is process-local — pair with Prometheus / StatsD exporters
 * (or {@see MonitoringDashboard}) when long-term aggregation is needed.
 */
class RpcLatencyRecorder
{
    /**
     * Bucket upper bounds in milliseconds. Anything larger than the last
     * bound falls into an implicit "+Inf" bucket.
     */
    const BUCKETS_MS = [1.0, 5.0, 10.0, 25.0, 50.0, 100.0, 250.0, 500.0, 1000.0, 2500.0, 5000.0, 10000.0];

    /**
     * @var array<string, array{count:int,failed:int,sum:float,min:float,max:float,buckets:array<int,int>}>
     */
    protected $stats = [];

    /**
     * @param string $key       Free-form identifier (e.g. "ServiceClass::method").
     * @param float  $ms        Elapsed time in milliseconds.
     * @param bool   $failed    Mark the call as failed (timed-out or remote error).
     * @return void
     */
    public function record(string $key, float $ms, bool $failed = false): void
    {
        if ($key === '') {
            return;
        }
        if (!isset($this->stats[$key])) {
            $this->stats[$key] = [
                'count' => 0,
                'failed' => 0,
                'sum' => 0.0,
                'min' => INF,
                'max' => 0.0,
                'buckets' => array_fill(0, count(self::BUCKETS_MS) + 1, 0),
            ];
        }
        $ms = max(0.0, $ms);

        $this->stats[$key]['count']++;
        if ($failed) {
            $this->stats[$key]['failed']++;
        }
        $this->stats[$key]['sum'] += $ms;
        if ($ms < $this->stats[$key]['min']) {
            $this->stats[$key]['min'] = $ms;
        }
        if ($ms > $this->stats[$key]['max']) {
            $this->stats[$key]['max'] = $ms;
        }

        $assigned = false;
        foreach (self::BUCKETS_MS as $i => $cap) {
            if ($ms <= $cap) {
                $this->stats[$key]['buckets'][$i]++;
                $assigned = true;
                break;
            }
        }
        if (!$assigned) {
            $this->stats[$key]['buckets'][count(self::BUCKETS_MS)]++;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function snapshot(): array
    {
        $out = [];
        foreach ($this->stats as $key => $entry) {
            $count = $entry['count'];
            $sum = $entry['sum'];
            $min = $entry['min'] === INF ? 0.0 : $entry['min'];

            $out[$key] = [
                'count' => $count,
                'failed' => $entry['failed'],
                'success' => $count - $entry['failed'],
                'error_rate' => $count > 0 ? $entry['failed'] / $count : 0.0,
                'avg_ms' => $count > 0 ? $sum / $count : 0.0,
                'min_ms' => $min,
                'max_ms' => $entry['max'],
                'p50_ms' => $this->percentile($entry, 0.50),
                'p95_ms' => $this->percentile($entry, 0.95),
                'p99_ms' => $this->percentile($entry, 0.99),
            ];
        }

        return $out;
    }

    /**
     * @return void
     */
    public function reset(): void
    {
        $this->stats = [];
    }

    /**
     * @param string $key
     * @return array<string, mixed>|null
     */
    public function for(string $key): ?array
    {
        $snap = $this->snapshot();
        return isset($snap[$key]) ? $snap[$key] : null;
    }

    /**
     * Estimate a percentile from the bucketed histogram.
     *
     * @param array{count:int,buckets:array<int,int>} $entry
     * @param float                                   $p     0.0–1.0
     * @return float
     */
    protected function percentile(array $entry, float $p): float
    {
        $count = $entry['count'];
        if ($count <= 0) {
            return 0.0;
        }

        $target = $p * $count;
        $cumulative = 0;
        $bounds = self::BUCKETS_MS;
        $bucketCount = count($bounds);

        foreach ($entry['buckets'] as $i => $hits) {
            $cumulative += $hits;
            if ($cumulative >= $target) {
                if ($i >= $bucketCount) {
                    // +Inf bucket — use the last finite bound as a lower bound.
                    return $bounds[$bucketCount - 1];
                }

                return $bounds[$i];
            }
        }

        return $bounds[$bucketCount - 1];
    }
}
