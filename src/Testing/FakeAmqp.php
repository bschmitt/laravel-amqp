<?php

namespace Bschmitt\Amqp\Testing;

use Bschmitt\Amqp\Contracts\BatchManagerInterface;
use Bschmitt\Amqp\Contracts\ConsumerFactoryInterface;
use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Factories\MessageFactory;
use Bschmitt\Amqp\Managers\BatchManager;

/**
 * Test double for {@see Amqp} that records publishes and never touches
 * the broker.
 *
 * Use {@see Amqp::fake()} from application code to swap the bound singleton.
 * Helper assertions follow Laravel's `Bus`/`Queue` fake conventions.
 */
class FakeAmqp extends Amqp
{
    /**
     * @var array<int, array{routing:string, message:mixed, properties:array<string, mixed>, type:string}>
     */
    protected $published = [];

    /**
     * @param PublisherFactoryInterface|null $publisherFactory
     * @param ConsumerFactoryInterface|null  $consumerFactory
     * @param MessageFactory|null            $messageFactory
     * @param BatchManagerInterface|null     $batchManager
     */
    public function __construct(
        ?PublisherFactoryInterface $publisherFactory = null,
        ?ConsumerFactoryInterface $consumerFactory = null,
        ?MessageFactory $messageFactory = null,
        ?BatchManagerInterface $batchManager = null
    ) {
        $publisherFactory = $publisherFactory ?: new NullPublisherFactory();
        $consumerFactory = $consumerFactory ?: new NullConsumerFactory();
        $messageFactory = $messageFactory ?: new MessageFactory();
        $batchManager = $batchManager ?: new BatchManager();

        parent::__construct($publisherFactory, $consumerFactory, $messageFactory, $batchManager);
    }

    /**
     * @param string $routing
     * @param mixed  $message
     * @param array<string, mixed> $properties
     * @return bool|null
     */
    public function publish(string $routing, $message, array $properties = []): ?bool
    {
        $this->record('publish', $routing, $message, $properties);

        return true;
    }

    /**
     * @param string $routing
     * @param mixed  $message
     * @param int    $delayMs
     * @param array<string, mixed> $properties
     * @return bool|null
     */
    public function publishLater(string $routing, $message, int $delayMs, array $properties = [])
    {
        $properties['__delay_ms'] = $delayMs;
        $this->record('publishLater', $routing, $message, $properties);

        return true;
    }

    /**
     * @return array<int, array{routing:string, message:mixed, properties:array<string, mixed>, type:string}>
     */
    public function published(): array
    {
        return $this->published;
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->published = [];
    }

    /**
     * Assert at least one publish matched the routing key (and optional filter).
     *
     * @param string        $routing
     * @param callable|null $callback function (array $publish): bool
     * @return void
     */
    public function assertPublished(string $routing, ?callable $callback = null): void
    {
        $matched = array_filter($this->published, function ($entry) use ($routing, $callback) {
            if ($entry['routing'] !== $routing) {
                return false;
            }
            return $callback === null ? true : (bool) $callback($entry);
        });

        if (empty($matched)) {
            throw new \PHPUnit\Framework\AssertionFailedError(sprintf(
                'Expected message published to [%s] but none matched (recorded %d publish(es))',
                $routing,
                count($this->published)
            ));
        }
    }

    /**
     * @param string $routing
     * @return void
     */
    public function assertNotPublished(string $routing): void
    {
        foreach ($this->published as $entry) {
            if ($entry['routing'] === $routing) {
                throw new \PHPUnit\Framework\AssertionFailedError(sprintf(
                    'Expected no message published to [%s] but found one',
                    $routing
                ));
            }
        }
    }

    /**
     * @return void
     */
    public function assertNothingPublished(): void
    {
        if (!empty($this->published)) {
            throw new \PHPUnit\Framework\AssertionFailedError(sprintf(
                'Expected no publishes but found %d',
                count($this->published)
            ));
        }
    }

    /**
     * @param int $count
     * @param string|null $routing
     * @return void
     */
    public function assertPublishedCount(int $count, ?string $routing = null): void
    {
        if ($routing === null) {
            $actual = count($this->published);
        } else {
            $actual = count(array_filter($this->published, function ($entry) use ($routing) {
                return $entry['routing'] === $routing;
            }));
        }

        if ($actual !== $count) {
            throw new \PHPUnit\Framework\AssertionFailedError(sprintf(
                'Expected %d publish(es)%s but got %d',
                $count,
                $routing !== null ? sprintf(' for [%s]', $routing) : '',
                $actual
            ));
        }
    }

    /**
     * @param string $type
     * @param string $routing
     * @param mixed  $message
     * @param array<string, mixed> $properties
     * @return void
     */
    protected function record(string $type, string $routing, $message, array $properties): void
    {
        $this->published[] = [
            'type' => $type,
            'routing' => $routing,
            'message' => $message,
            'properties' => $properties,
        ];
    }
}
