<?php

namespace Bschmitt\Amqp\Test\Unit\Pulse;

use Bschmitt\Amqp\Events\DeadLetterDetected;
use Bschmitt\Amqp\Events\MessageFailed;
use Bschmitt\Amqp\Events\MessageHandled;
use Bschmitt\Amqp\Events\MessagePublished;
use Bschmitt\Amqp\Events\RpcCallCompleted;
use Bschmitt\Amqp\Events\RpcCallFailed;
use Bschmitt\Amqp\Pulse\AmqpPulseRecorder;
use Bschmitt\Amqp\Test\Support\BaseTestCase;
use PhpAmqpLib\Message\AMQPMessage;

class AmqpPulseRecorderTest extends BaseTestCase
{
    /** @var array<int, array{0:string,1:string,2:int}> */
    private $captured = [];

    /** @var AmqpPulseRecorder */
    private $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captured = [];
        $this->recorder = new AmqpPulseRecorder();
        $this->recorder->setRecordHook(function ($type, $key, $value) {
            $this->captured[] = [$type, $key, $value];
        });
    }

    public function testRecordPublishedEmitsPublishCount(): void
    {
        $event = new MessagePublished('orders.created', 'payload', [], true);

        $this->recorder->recordPublished($event);

        $this->assertCount(1, $this->captured);
        $this->assertSame(['amqp_publish', 'orders.created', 1], $this->captured[0]);
    }

    public function testRecordHandledEmitsRoundedDuration(): void
    {
        $event = new MessageHandled('orders.consumer', new AMQPMessage('body'), 12.7);

        $this->recorder->recordHandled($event);

        $this->assertSame(['amqp_handle', 'orders.consumer', 13], $this->captured[0]);
    }

    public function testRecordFailedEmitsFailCount(): void
    {
        $event = new MessageFailed('orders.consumer', new AMQPMessage('body'), new \RuntimeException('boom'));

        $this->recorder->recordFailed($event);

        $this->assertSame(['amqp_fail', 'orders.consumer', 1], $this->captured[0]);
    }

    public function testRecordRpcCompletedShortensClassNames(): void
    {
        $event = new RpcCallCompleted(
            'App\\Services\\UserService',
            'App\\Rpc\\GetUserRequest',
            42.3,
            'corr_1'
        );

        $this->recorder->recordRpcCompleted($event);

        $this->assertSame('amqp_rpc', $this->captured[0][0]);
        $this->assertSame('UserService::GetUserRequest', $this->captured[0][1]);
        $this->assertSame(42, $this->captured[0][2]);
    }

    public function testRecordRpcFailedEmitsFailCount(): void
    {
        $event = new RpcCallFailed(
            'UserService',
            'GetUserRequest',
            10.0,
            true
        );

        $this->recorder->recordRpcFailed($event);

        $this->assertSame(['amqp_rpc_fail', 'UserService::GetUserRequest', 1], $this->captured[0]);
    }

    public function testRecordDeadLetterEmitsAtLeastOne(): void
    {
        $event = new DeadLetterDetected('orders.dlq', 7);

        $this->recorder->recordDeadLetter($event);

        $this->assertSame(['amqp_dlq', 'orders.dlq', 7], $this->captured[0]);
    }

    public function testRecordDeadLetterFloorsZeroToOne(): void
    {
        $event = new DeadLetterDetected('orders.dlq', 0);

        $this->recorder->recordDeadLetter($event);

        $this->assertSame(1, $this->captured[0][2]);
    }

    public function testIsPulseAvailableTrueWhenHookSet(): void
    {
        $this->assertTrue($this->recorder->isPulseAvailable());
    }

    public function testWithoutHookOrPulseInstalledIsNoOp(): void
    {
        $recorder = new AmqpPulseRecorder();

        // Pulse is not installed in the test environment so this must be a
        // silent no-op. The contract: never throw, never write anywhere.
        $recorder->recordPublished(new MessagePublished('x', null, [], true));

        $this->assertFalse($recorder->isPulseAvailable());
    }
}
