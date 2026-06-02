<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Models\Message;

/**
 * Asynchronous publisher with publisher confirms.
 *
 * Reuses a single publisher (and therefore a single channel) for many
 * `publish()` calls, enabling publisher confirms once and waiting for all
 * pending acks only when {@see flush()} is called. Provides ack/nack
 * callbacks so the caller can correlate confirms back to logical messages.
 *
 * Usage:
 *
 *   $async = $amqp->asyncPublisher(['exchange' => 'events'])
 *       ->onAck(function ($deliveryTag) { ... })
 *       ->onNack(function ($deliveryTag) { ... });
 *
 *   foreach ($messages as $m) {
 *       $async->publish('events.created', json_encode($m));
 *   }
 *
 *   $async->flush(); // waits for all pending acks
 *   $async->close();
 */
class AsyncPublisher
{
    /** @var PublisherFactoryInterface */
    protected $publisherFactory;

    /** @var MessageFactory */
    protected $messageFactory;

    /** @var array<string, mixed> */
    protected $properties;

    /** @var Publisher|null */
    protected $publisher;

    /** @var int */
    protected $pending = 0;

    /** @var int */
    protected $published = 0;

    /** @var int */
    protected $acked = 0;

    /** @var int */
    protected $nacked = 0;

    /** @var callable|null */
    protected $onAck;

    /** @var callable|null */
    protected $onNack;

    /**
     * @param PublisherFactoryInterface $publisherFactory
     * @param MessageFactory            $messageFactory
     * @param array<string, mixed>      $properties Base publish properties (exchange, queue, etc).
     */
    public function __construct(
        PublisherFactoryInterface $publisherFactory,
        MessageFactory $messageFactory,
        array $properties = []
    ) {
        $this->publisherFactory = $publisherFactory;
        $this->messageFactory = $messageFactory;
        $this->properties = array_merge(['publisher_confirms' => true, 'wait_for_confirms' => false], $properties);
    }

    /**
     * @param callable $callback function ($deliveryTag, $msg): void
     * @return $this
     */
    public function onAck(callable $callback): self
    {
        $this->onAck = $callback;

        return $this;
    }

    /**
     * @param callable $callback function ($deliveryTag, $msg): void
     * @return $this
     */
    public function onNack(callable $callback): self
    {
        $this->onNack = $callback;

        return $this;
    }

    /**
     * Publish a message without waiting for the broker ack.
     *
     * @param string         $routing
     * @param string|Message $message
     * @param array<string, mixed> $messageProperties Per-message overrides (priority, headers, ...).
     * @return void
     */
    public function publish(string $routing, $message, array $messageProperties = []): void
    {
        $publisher = $this->getPublisher();

        $appHeaders = (array) ($messageProperties['application_headers'] ?? []);
        unset($messageProperties['application_headers']);

        $built = $this->messageFactory->create($message, $appHeaders, $messageProperties);

        $publisher->publish($routing, $built, false);
        $this->published++;
        $this->pending++;
    }

    /**
     * Wait for all outstanding confirms.
     *
     * @param int|null $timeoutSeconds
     * @return bool True if every pending ack was received.
     */
    public function flush(?int $timeoutSeconds = null): bool
    {
        if ($this->publisher === null || $this->pending === 0) {
            $this->pending = 0;

            return true;
        }

        $result = $this->publisher->waitForConfirms($timeoutSeconds);
        $this->pending = 0;

        return $result;
    }

    /**
     * Close the underlying publisher's connection.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->publisher === null) {
            return;
        }

        $manager = $this->publisher->getConnectionManager();
        if ($manager !== null) {
            $manager->disconnect();
        }

        $this->publisher = null;
        $this->pending = 0;
    }

    /**
     * @return array{published:int, acked:int, nacked:int, pending:int}
     */
    public function stats(): array
    {
        return [
            'published' => $this->published,
            'acked' => $this->acked,
            'nacked' => $this->nacked,
            'pending' => $this->pending,
        ];
    }

    /**
     * @return Publisher
     */
    protected function getPublisher(): Publisher
    {
        if ($this->publisher !== null) {
            return $this->publisher;
        }

        $publisher = $this->publisherFactory->create($this->properties);
        if (!($publisher instanceof Publisher)) {
            throw new \RuntimeException('AsyncPublisher requires the bundled Publisher implementation');
        }

        $publisher->enablePublisherConfirms();
        $publisher->setAckHandler(function ($msg) {
            $this->acked++;
            if ($this->pending > 0) {
                $this->pending--;
            }
            if ($this->onAck !== null) {
                call_user_func($this->onAck, $msg->getDeliveryTag(), $msg);
            }
        });
        $publisher->setNackHandler(function ($msg) {
            $this->nacked++;
            if ($this->pending > 0) {
                $this->pending--;
            }
            if ($this->onNack !== null) {
                call_user_func($this->onNack, $msg->getDeliveryTag(), $msg);
            }
        });

        $this->publisher = $publisher;

        return $publisher;
    }
}
