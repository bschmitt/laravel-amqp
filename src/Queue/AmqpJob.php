<?php

namespace Bschmitt\Amqp\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class AmqpJob extends Job implements JobContract
{
    /**
     * @var AMQPMessage
     */
    protected $message;

    /**
     * @var AmqpQueue
     */
    protected $amqpQueue;

    /**
     * @param Container $container
     * @param AmqpQueue $amqpQueue
     * @param AMQPMessage $message
     * @param string $connectionName
     * @param string $queue
     */
    public function __construct(
        Container $container,
        AmqpQueue $amqpQueue,
        AMQPMessage $message,
        $connectionName,
        $queue
    ) {
        $this->container = $container;
        $this->amqpQueue = $amqpQueue;
        $this->message = $message;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    /**
     * {@inheritdoc}
     */
    public function getJobId()
    {
        return $this->payload()['id'] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function uuid()
    {
        return $this->getJobId();
    }

    /**
     * {@inheritdoc}
     */
    public function attempts()
    {
        return $this->getLaravelAttempts() + 1;
    }

    /**
     * {@inheritdoc}
     */
    public function getRawBody()
    {
        return $this->message->getBody();
    }

    /**
     * @return AMQPMessage
     */
    public function getAmqpMessage(): AMQPMessage
    {
        return $this->message;
    }

    /**
     * {@inheritdoc}
     */
    public function delete()
    {
        parent::delete();

        $this->amqpQueue->ack($this);
    }

    /**
     * {@inheritdoc}
     */
    public function release($delay = 0)
    {
        parent::release($delay);

        if ($delay > 0) {
            $this->amqpQueue->laterRaw(
                $delay,
                $this->getRawBody(),
                $this->queue,
                $this->getLaravelAttempts()
            );
            $this->amqpQueue->ack($this);
        } else {
            $this->amqpQueue->reject($this, true);
        }
    }

    /**
     * @return int
     */
    protected function getLaravelAttempts(): int
    {
        if (!$this->message->has('application_headers')) {
            return 0;
        }

        $headers = $this->message->get('application_headers');

        if ($headers instanceof AMQPTable) {
            $data = $headers->getNativeData();

            if (isset($data['laravel']['attempts'])) {
                return (int) $data['laravel']['attempts'];
            }
        }

        return 0;
    }
}
