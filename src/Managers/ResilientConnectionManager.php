<?php

namespace Bschmitt\Amqp\Managers;

use Bschmitt\Amqp\Contracts\ConnectionManagerInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Decorator that reconnects on stale connections and transient connect failures.
 *
 * Wrap an existing {@see ConnectionManagerInterface} (typically
 * {@see ConnectionManager}) and call {@see ensureConnected()} before broker
 * operations. Heartbeat monitoring uses the configured interval multiplied by
 * two as a staleness threshold when no wire activity has been recorded.
 */
class ResilientConnectionManager implements ConnectionManagerInterface
{
    /** @var ConnectionManagerInterface */
    protected $inner;

    /** @var int */
    protected $maxReconnectAttempts;

    /** @var int */
    protected $reconnectDelayMs;

    /** @var int */
    protected $heartbeatSeconds;

    /** @var float */
    protected $lastActivity = 0.0;

    /**
     * @param ConnectionManagerInterface $inner
     * @param array<string, mixed>       $options max_reconnect_attempts, reconnect_delay_ms, heartbeat
     */
    public function __construct(ConnectionManagerInterface $inner, array $options = [])
    {
        $this->inner = $inner;
        $this->maxReconnectAttempts = (int) ($options['max_reconnect_attempts'] ?? 3);
        $this->reconnectDelayMs = (int) ($options['reconnect_delay_ms'] ?? 250);
        $this->heartbeatSeconds = (int) ($options['heartbeat'] ?? 60);
    }

    /**
     * {@inheritdoc}
     */
    public function connect(): void
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxReconnectAttempts) {
            try {
                $this->inner->connect();
                $this->touchActivity();

                return;
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt >= $this->maxReconnectAttempts) {
                    break;
                }
                usleep($this->reconnectDelayMs * 1000);
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getChannel(): AMQPChannel
    {
        $this->ensureConnected();

        return $this->inner->getChannel();
    }

    /**
     * {@inheritdoc}
     */
    public function getConnection(): AMQPStreamConnection
    {
        $this->ensureConnected();

        return $this->inner->getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        $this->inner->disconnect();
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    /**
     * Reconnect when the connection is down or the heartbeat window elapsed.
     *
     * @return void
     */
    public function ensureConnected(): void
    {
        if (!$this->isConnected() || $this->isHeartbeatStale()) {
            $this->reconnect();
        }
    }

    /**
     * Record broker activity (call after successful publish/consume).
     *
     * @return void
     */
    public function touchActivity(): void
    {
        $this->lastActivity = microtime(true);
    }

    /**
     * @return ConnectionManagerInterface
     */
    public function getInner(): ConnectionManagerInterface
    {
        return $this->inner;
    }

    /**
     * @return void
     */
    protected function reconnect(): void
    {
        try {
            $this->inner->disconnect();
        } catch (\Throwable $e) {
            // Best-effort cleanup before reconnect.
        }

        $this->connect();
    }

    /**
     * @return bool
     */
    protected function isHeartbeatStale(): bool
    {
        if ($this->heartbeatSeconds <= 0) {
            return false;
        }

        if ($this->lastActivity <= 0.0) {
            return false;
        }

        $threshold = $this->heartbeatSeconds * 2;

        return (microtime(true) - $this->lastActivity) > $threshold;
    }
}
