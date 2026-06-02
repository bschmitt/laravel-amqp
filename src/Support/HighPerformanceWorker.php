<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\ConsumePipeline;
use Closure;

/**
 * Long-running consumer with throughput-oriented defaults.
 *
 * Combines {@see WorkerOptions}, optional {@see ConsumerLifecycle} hooks,
 * metrics recording, and middleware support into a single entry point
 * suitable for dedicated worker processes.
 */
class HighPerformanceWorker
{
    /** @var Amqp */
    protected $amqp;

    /** @var WorkerOptions */
    protected $options;

    /**
     * @param Amqp               $amqp
     * @param WorkerOptions|null $options
     */
    public function __construct(Amqp $amqp, ?WorkerOptions $options = null)
    {
        $this->amqp = $amqp;
        $this->options = $options !== null ? $options : WorkerOptions::throughput();
    }

    /**
     * @param string                                                          $queue
     * @param Closure                                                         $handler
     * @param array<string, mixed>                                            $properties
     * @param ConsumerLifecycle|null                                          $lifecycle
     * @param array<int, callable|\Bschmitt\Amqp\Contracts\ConsumeMiddlewareInterface>|null $middlewares
     * @return bool
     */
    public function run(
        string $queue,
        Closure $handler,
        array $properties = [],
        ?ConsumerLifecycle $lifecycle = null,
        ?array $middlewares = null
    ): bool {
        $properties = $this->options->mergeInto($properties);

        $wrapped = function ($message, $resolver) use ($handler, $queue) {
            $this->amqp->metrics()->incrementConsumed($queue);
            try {
                $result = $handler($message, $resolver);
                $this->amqp->metrics()->incrementHandled();

                return $result;
            } catch (\Throwable $e) {
                $this->amqp->metrics()->incrementFailed($queue);
                throw $e;
            }
        };

        if ($middlewares !== null && $middlewares !== []) {
            $pipeline = (new ConsumePipeline())->pushMany($middlewares);
            $wrapped = $pipeline->wrap($wrapped);
        }

        if ($lifecycle !== null) {
            return $this->amqp->consumeWithLifecycle($queue, $wrapped, $lifecycle, $properties);
        }

        return $this->amqp->consume($queue, $wrapped, $properties);
    }
}
