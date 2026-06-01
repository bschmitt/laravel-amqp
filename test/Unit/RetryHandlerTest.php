<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\PublisherFactoryInterface;
use Bschmitt\Amqp\Contracts\PublisherInterface;
use Bschmitt\Amqp\Models\Message as AmqpModelMessage;
use Bschmitt\Amqp\Support\DeadLetterTopology;
use Bschmitt\Amqp\Support\RetryHandler;
use Bschmitt\Amqp\Support\RetryPolicy;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

/**
 * Behavioural coverage for {@see RetryHandler}.
 *
 * The handler stitches together three external concerns (publisher,
 * consumer/resolver, and the user handler), so the tests use Mockery
 * doubles for each and assert the wiring directly: how attempts are
 * counted, when retries fire, what the replay payload looks like, and
 * what happens when the retry budget runs out.
 */
class RetryHandlerTest extends BaseTestCase
{
    public function testSuccessfulHandlerIsNotRetriedAndNotAcked(): void
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $factory->shouldNotReceive('create');

        $resolver = Mockery::mock(ConsumerInterface::class);
        $resolver->shouldNotReceive('reject');
        $resolver->shouldNotReceive('acknowledge');

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(3, 100));
        $invoked = false;
        $handler = function () use (&$invoked) {
            $invoked = true;
        };

        $wrapper = new RetryHandler($handler, $topology, $factory);
        $wrapper->handle(new AMQPMessage('payload'), $resolver);

        $this->assertTrue($invoked, 'inner handler must run');
    }

    public function testFailingHandlerRepublishesToRetryQueueAndAcks(): void
    {
        $captured = $this->captureRetryPublish();

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(3, 1500))
            ->on('amq.topic', 'topic');

        $resolver = Mockery::mock(ConsumerInterface::class);
        $resolver->shouldReceive('acknowledge')->once();
        $resolver->shouldNotReceive('reject');

        $handler = function () {
            throw new RuntimeException('boom');
        };

        $wrapper = new RetryHandler($handler, $topology, $captured['factory']);
        $wrapper->handle(new AMQPMessage('payload'), $resolver);

        $this->assertSame('jobs.retry.1500', $captured['ref']->routingKey);
        $this->assertInstanceOf(AmqpModelMessage::class, $captured['ref']->message);
        $this->assertSame('payload', $captured['ref']->message->getBody());
        $this->assertSame('jobs.retry.1500', $captured['ref']->properties['queue']);

        $qp = $captured['ref']->properties['queue_properties'];
        $this->assertSame(1500, $qp['x-message-ttl']);
        $this->assertSame('amq.topic', $qp['x-dead-letter-exchange']);
        $this->assertSame('jobs', $qp['x-dead-letter-routing-key']);

        $headers = $this->headerArray($captured['ref']->message);
        $this->assertSame(1, $headers[RetryHandler::ATTEMPT_HEADER]);
        $this->assertArrayHasKey(RetryHandler::FIRST_FAILED_AT_HEADER, $headers);
        $this->assertSame('boom', $headers[RetryHandler::LAST_ERROR_HEADER]);
    }

    public function testRetryPreservesPriorApplicationHeaders(): void
    {
        $captured = $this->captureRetryPublish();

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(3, 1000))->on('app.events');

        $resolver = Mockery::mock(ConsumerInterface::class);
        $resolver->shouldReceive('acknowledge')->once();

        $message = new AMQPMessage('payload', [
            'application_headers' => new AMQPTable([
                'tenant' => 'acme',
                RetryHandler::FIRST_FAILED_AT_HEADER => '2026-01-01T00:00:00+00:00',
            ]),
            'correlation_id' => 'corr-1',
        ]);

        $wrapper = new RetryHandler(
            function () {
                throw new RuntimeException('still failing');
            },
            $topology,
            $captured['factory']
        );

        $wrapper->handle($message, $resolver);

        $headers = $this->headerArray($captured['ref']->message);
        $this->assertSame('acme', $headers['tenant']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $headers[RetryHandler::FIRST_FAILED_AT_HEADER]);
        $this->assertSame(1, $headers[RetryHandler::ATTEMPT_HEADER]);

        $props = $captured['ref']->message->get_properties();
        $this->assertSame('corr-1', $props['correlation_id'] ?? null);
    }

    public function testRetryAttemptHeaderIsIncrementedAcrossDeliveries(): void
    {
        $captured = $this->captureRetryPublish();

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(5, 800));

        $resolver = Mockery::mock(ConsumerInterface::class);
        $resolver->shouldReceive('acknowledge')->once();

        $message = new AMQPMessage('payload', [
            'application_headers' => new AMQPTable([
                RetryHandler::ATTEMPT_HEADER => 2,
            ]),
        ]);

        $wrapper = new RetryHandler(
            function () {
                throw new RuntimeException('still failing');
            },
            $topology,
            $captured['factory']
        );

        $wrapper->handle($message, $resolver);

        $headers = $this->headerArray($captured['ref']->message);
        $this->assertSame(3, $headers[RetryHandler::ATTEMPT_HEADER]);
    }

    public function testRetryUsesExponentialDelayForCurrentAttempt(): void
    {
        $captured = $this->captureRetryPublish();

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::exponential(5, 100, 2.0));

        $resolver = Mockery::mock(ConsumerInterface::class);
        $resolver->shouldReceive('acknowledge')->once();

        $message = new AMQPMessage('payload', [
            'application_headers' => new AMQPTable([RetryHandler::ATTEMPT_HEADER => 2]),
        ]);

        $wrapper = new RetryHandler(
            function () {
                throw new RuntimeException('still failing');
            },
            $topology,
            $captured['factory']
        );

        $wrapper->handle($message, $resolver);

        // attempt 2 has failed → next attempt is #3 → delay = 100 * 2^(3-1) = 400ms
        $this->assertSame('jobs.retry.400', $captured['ref']->routingKey);
        $this->assertSame(400, $captured['ref']->properties['queue_properties']['x-message-ttl']);
    }

    public function testRetryExhaustionRejectsWithoutRequeue(): void
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $factory->shouldNotReceive('create');

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(2, 100));

        $rejected = null;
        $resolver = Mockery::mock(ConsumerInterface::class);
        $resolver->shouldReceive('reject')
            ->once()
            ->andReturnUsing(function ($message, $requeue) use (&$rejected) {
                $rejected = ['message' => $message, 'requeue' => $requeue];
            });
        $resolver->shouldNotReceive('acknowledge');

        $message = new AMQPMessage('payload', [
            'application_headers' => new AMQPTable([RetryHandler::ATTEMPT_HEADER => 2]),
        ]);

        $wrapper = new RetryHandler(
            function () {
                throw new RuntimeException('done trying');
            },
            $topology,
            $factory
        );

        $wrapper->handle($message, $resolver);

        $this->assertSame($message, $rejected['message']);
        $this->assertFalse($rejected['requeue'], 'must not requeue when retries are exhausted');
    }

    public function testNonePolicyDeadLettersImmediatelyOnFailure(): void
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $factory->shouldNotReceive('create');

        $topology = DeadLetterTopology::for('jobs');

        $rejected = null;
        $resolver = Mockery::mock(ConsumerInterface::class);
        $resolver->shouldReceive('reject')
            ->once()
            ->andReturnUsing(function ($message, $requeue) use (&$rejected) {
                $rejected = ['message' => $message, 'requeue' => $requeue];
            });

        $wrapper = new RetryHandler(
            function () {
                throw new RuntimeException('nope');
            },
            $topology,
            $factory
        );

        $wrapper->handle(new AMQPMessage('payload'), $resolver);

        $this->assertInstanceOf(AMQPMessage::class, $rejected['message']);
        $this->assertFalse($rejected['requeue']);
    }

    public function testCurrentAttemptFallsBackToXDeathCountWhenHeaderMissing(): void
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $topology = DeadLetterTopology::for('jobs');

        $wrapper = new RetryHandler(function () {}, $topology, $factory);

        $message = new AMQPMessage('payload', [
            'application_headers' => new AMQPTable([
                'x-death' => [
                    ['queue' => 'jobs', 'count' => 2],
                    ['queue' => 'jobs.retry.1000', 'count' => 1],
                ],
            ]),
        ]);

        $this->assertSame(3, $wrapper->currentAttempt($message));
    }

    public function testRepublishFailureCausesDeadLetterFallback(): void
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $publisher = Mockery::mock(PublisherInterface::class);

        $factory->shouldReceive('create')
            ->once()
            ->andReturn($publisher);
        $publisher->shouldReceive('publish')
            ->once()
            ->andThrow(new RuntimeException('broker down'));

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(3, 100));

        $rejected = null;
        $resolver = Mockery::mock(ConsumerInterface::class);
        $resolver->shouldReceive('reject')
            ->once()
            ->andReturnUsing(function ($message, $requeue) use (&$rejected) {
                $rejected = ['message' => $message, 'requeue' => $requeue];
            });
        $resolver->shouldNotReceive('acknowledge');

        $wrapper = new RetryHandler(
            function () {
                throw new RuntimeException('handler boom');
            },
            $topology,
            $factory
        );

        $wrapper->handle(new AMQPMessage('payload'), $resolver);

        $this->assertInstanceOf(AMQPMessage::class, $rejected['message']);
        $this->assertFalse($rejected['requeue']);
    }

    public function testLoggerReceivesWarningOnRetry(): void
    {
        $captured = $this->captureRetryPublish();

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(3, 250));
        $resolver = Mockery::mock(ConsumerInterface::class);
        $resolver->shouldReceive('acknowledge')->once();

        $events = [];
        $logger = function ($level, $message, $context = []) use (&$events) {
            $events[] = compact('level', 'message', 'context');
        };

        $wrapper = new RetryHandler(
            function () {
                throw new RuntimeException('first fail');
            },
            $topology,
            $captured['factory'],
            null,
            $logger
        );
        $wrapper->handle(new AMQPMessage('payload'), $resolver);

        $this->assertCount(1, $events);
        $this->assertSame('warning', $events[0]['level']);
        $this->assertStringContainsString('first fail', $events[0]['message']);
    }

    public function testLoggerReceivesErrorWhenRetriesAreExhausted(): void
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $factory->shouldNotReceive('create');

        $topology = DeadLetterTopology::for('jobs', RetryPolicy::fixed(1, 100));
        $resolver = Mockery::mock(ConsumerInterface::class);
        $resolver->shouldReceive('reject')->once();

        $message = new AMQPMessage('payload', [
            'application_headers' => new AMQPTable([RetryHandler::ATTEMPT_HEADER => 1]),
        ]);

        $events = [];
        $logger = function ($level, $message, $context = []) use (&$events) {
            $events[] = compact('level', 'message', 'context');
        };

        $wrapper = new RetryHandler(
            function () {
                throw new RuntimeException('keep failing');
            },
            $topology,
            $factory,
            null,
            $logger
        );
        $wrapper->handle($message, $resolver);

        $this->assertSame('error', $events[0]['level']);
        $this->assertStringContainsString('Retries exhausted', $events[0]['message']);
    }

    public function testWrapFactoryProducesEquivalentInstance(): void
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $topology = DeadLetterTopology::for('jobs');

        $wrapper = RetryHandler::wrap(function () {}, $topology, $factory);
        $this->assertInstanceOf(RetryHandler::class, $wrapper);
        $this->assertSame($topology, $wrapper->getTopology());
    }

    /**
     * Wire up a publisher mock that records the single publish() call.
     *
     * @return array{factory: PublisherFactoryInterface, ref: \stdClass}
     */
    private function captureRetryPublish(): array
    {
        $factory = Mockery::mock(PublisherFactoryInterface::class);
        $publisher = Mockery::mock(PublisherInterface::class);

        $ref = new \stdClass();
        $ref->routingKey = null;
        $ref->message = null;
        $ref->properties = null;

        $factory->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $properties) use ($ref, $publisher) {
                $ref->properties = $properties;
                return $publisher;
            });

        $publisher->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($routingKey, $message) use ($ref) {
                $ref->routingKey = $routingKey;
                $ref->message = $message;
                return true;
            });

        return ['factory' => $factory, 'ref' => $ref];
    }

    /**
     * @return array<string, mixed>
     */
    private function headerArray(AMQPMessage $message): array
    {
        $props = $message->get_properties();
        $headers = $props['application_headers'] ?? null;
        if ($headers instanceof AMQPTable) {
            return $headers->getNativeData();
        }
        return is_array($headers) ? $headers : [];
    }
}
