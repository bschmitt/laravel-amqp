<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Console\HandlerResolver;
use Bschmitt\Amqp\Core\Amqp;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Listen on one or more routing keys via an auto-generated (or named) queue.
 *
 * This is a thin CLI wrapper around {@see Amqp::listen()} — it creates a
 * queue (auto-deleted by default), binds it to every supplied routing key
 * on the configured topic exchange, and dispatches messages to a handler.
 *
 *   php artisan amqp:listen order.created order.updated --handler=App\\Messaging\\OrderHandler
 */
class AmqpListenCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:listen
                            {routing-keys* : One or more routing keys to listen on (e.g. order.created order.updated)}
                            {--handler= : FQCN of the message handler}
                            {--queue= : Queue name (auto-generated when omitted)}
                            {--connection= : Connection name from config/amqp.php}
                            {--exchange= : Override the exchange name}
                            {--exchange-type=topic : Exchange type (topic, direct, fanout, headers)}
                            {--prefetch-count= : Number of messages to prefetch}
                            {--max-messages=0 : Stop after processing N messages (0 = unlimited)}
                            {--max-time=0 : Stop after N seconds (0 = unlimited)}
                            {--no-auto-delete : Keep the queue around when the listener disconnects}
                            {--requeue-on-error : Requeue messages whose handler throws}';

    /** @var string */
    protected $description = 'Listen on one or more routing keys with an auto-generated queue.';

    /** @var Amqp */
    protected $amqp;

    /** @var HandlerResolver */
    protected $resolver;

    /** @var int */
    protected $processed = 0;

    /** @var int */
    protected $failed = 0;

    public function __construct(Amqp $amqp, ?HandlerResolver $resolver = null)
    {
        parent::__construct();
        $this->amqp = $amqp;
        $this->resolver = $resolver ?? new HandlerResolver();
    }

    public function handle(): int
    {
        $routingKeys = (array) $this->argument('routing-keys');
        $routingKeys = array_values(array_filter($routingKeys, function ($v) {
            return $v !== null && $v !== '';
        }));

        if (empty($routingKeys)) {
            $this->error('At least one routing key argument is required.');
            return self::INVALID;
        }

        $handlerOpt = $this->option('handler');
        if (!$handlerOpt) {
            $this->error('The --handler option is required.');
            return self::INVALID;
        }

        if ($this->laravel !== null) {
            $this->resolver = new HandlerResolver($this->laravel);
        }

        try {
            $callable = $this->resolver->resolve($handlerOpt);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $maxMessages = max(0, (int) $this->option('max-messages'));
        $maxTime = max(0, (int) $this->option('max-time'));
        $requeueOnError = (bool) $this->option('requeue-on-error');

        $properties = $this->buildProperties();
        $startTime = time();

        $this->info(sprintf(
            'Listening on routing keys [%s] (queue: %s)...',
            implode(', ', $routingKeys),
            $properties['queue'] ?? '<auto>'
        ));

        $callback = function (AMQPMessage $message, $resolverConsumer) use (
            $callable,
            $maxMessages,
            $maxTime,
            $requeueOnError,
            $startTime
        ) {
            try {
                $callable($message, $resolverConsumer);
                $this->processed++;
            } catch (Throwable $e) {
                $this->failed++;
                $this->error(sprintf('Handler error: %s', $e->getMessage()));
                if (is_object($resolverConsumer) && method_exists($resolverConsumer, 'reject')) {
                    try {
                        $resolverConsumer->reject($message, $requeueOnError);
                    } catch (Throwable $ignored) {
                    }
                }
            }

            $shouldStop = false;
            if ($maxMessages > 0 && ($this->processed + $this->failed) >= $maxMessages) {
                $shouldStop = true;
            }
            if ($maxTime > 0 && (time() - $startTime) >= $maxTime) {
                $shouldStop = true;
            }

            if ($shouldStop && is_object($resolverConsumer) && method_exists($resolverConsumer, 'stopWhenProcessed')) {
                $resolverConsumer->stopWhenProcessed();
            }
        };

        try {
            $this->amqp->listen($routingKeys, $callback, $properties);
        } catch (Throwable $e) {
            $this->error('Listener error: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf('amqp:listen exited. Processed: %d, failed: %d.', $this->processed, $this->failed));

        return $this->failed > 0 && $this->processed === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildProperties(): array
    {
        $props = [
            'exchange_type' => (string) ($this->option('exchange-type') ?: 'topic'),
        ];

        if ($connection = $this->option('connection')) {
            $props['use'] = (string) $connection;
        }
        if ($queueName = $this->option('queue')) {
            $props['queue'] = (string) $queueName;
        }
        if ($exchange = $this->option('exchange')) {
            $props['exchange'] = (string) $exchange;
        }
        if ($this->option('no-auto-delete')) {
            $props['queue_auto_delete'] = false;
        }

        $prefetch = $this->option('prefetch-count');
        if ($prefetch !== null && $prefetch !== '') {
            $props['qos'] = true;
            $props['qos_prefetch_count'] = (int) $prefetch;
        }

        $props['persistent'] = true;

        return $props;
    }
}
