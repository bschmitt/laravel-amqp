<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Queue\AmqpJob;
use Bschmitt\Amqp\Queue\AmqpQueue;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use Illuminate\Container\Container;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Unit tests for {@see AmqpJob}.
 *
 * The Job ↔ Queue contract is verified by mocking AmqpQueue and asserting
 * that delete/release translate into the correct ack/reject/laterRaw calls
 * with the right arguments.
 */
class AmqpJobTest extends BaseTestCase
{
    public function testGetRawBodyAndJobIdAreExtractedFromPayload(): void
    {
        $payload = json_encode(['id' => 'job-uuid-1', 'data' => []]);

        [$job] = $this->makeJob($payload);

        $this->assertSame($payload, $job->getRawBody());
        $this->assertSame('job-uuid-1', $job->getJobId());
        $this->assertSame('job-uuid-1', $job->uuid());
    }

    public function testAttemptsDefaultsToOneWhenHeaderMissing(): void
    {
        [$job] = $this->makeJob(json_encode(['id' => 'x']));

        $this->assertSame(1, $job->attempts());
    }

    public function testAttemptsReadsLaravelAttemptsHeaderAndIncrements(): void
    {
        $headers = new AMQPTable(['laravel' => ['attempts' => 4]]);

        [$job] = $this->makeJob(json_encode(['id' => 'x']), ['application_headers' => $headers]);

        $this->assertSame(5, $job->attempts(), 'attempts() returns header value + 1 (current execution)');
    }

    public function testDeleteMarksJobAsDeletedAndAcksOnce(): void
    {
        [$job, $queue] = $this->makeJob(json_encode(['id' => 'x']));

        $queue->shouldReceive('ack')
            ->once()
            ->with(Mockery::on(function (AmqpJob $passed) use ($job) {
                return $passed === $job;
            }));

        $job->delete();

        $this->assertTrue($job->isDeleted());
    }

    public function testReleaseWithoutDelayRejectsWithRequeueTrue(): void
    {
        [$job, $queue] = $this->makeJob(json_encode(['id' => 'x']));

        $queue->shouldReceive('reject')
            ->once()
            ->with(Mockery::type(AmqpJob::class), true);
        $queue->shouldNotReceive('ack');
        $queue->shouldNotReceive('laterRaw');

        $job->release(0);

        $this->assertTrue($job->isReleased());
    }

    public function testReleaseWithDelayRepublishesViaLaterRawAndAcksOriginal(): void
    {
        $payload = json_encode(['id' => 'x']);
        $headers = new AMQPTable(['laravel' => ['attempts' => 2]]);

        [$job, $queue] = $this->makeJob($payload, ['application_headers' => $headers], 'jobs');

        $queue->shouldReceive('laterRaw')
            ->once()
            ->with(30, $payload, 'jobs', 2);

        $queue->shouldReceive('ack')
            ->once()
            ->with(Mockery::type(AmqpJob::class));

        $queue->shouldNotReceive('reject');

        $job->release(30);

        $this->assertTrue($job->isReleased());
    }

    /**
     * Build an AmqpJob with a mocked AmqpQueue.
     *
     * @return array{0: AmqpJob, 1: \Mockery\MockInterface&AmqpQueue}
     */
    private function makeJob(string $payload, array $messageProperties = [], string $queueName = 'default'): array
    {
        $message = new AMQPMessage($payload, $messageProperties);
        $message->setDeliveryTag(1);

        /** @var AmqpQueue&\Mockery\MockInterface $queue */
        $queue = Mockery::mock(AmqpQueue::class);

        $job = new AmqpJob(
            new Container(),
            $queue,
            $message,
            'amqp',
            $queueName
        );

        return [$job, $queue];
    }
}
