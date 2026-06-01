<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Console\HandlerResolver;
use Bschmitt\Amqp\Core\Amqp;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * One-shot AMQP consumer — drains a fixed number of messages from a queue
 * and exits. Useful for cron-driven processing or scripted workflows where
 * a perpetually running worker is not desired.
 *
 * Defaults to processing a single message. Use `--max-messages` to drain
 * more, or `--all` to keep going until the queue is empty.
 */
class AmqpConsumeCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:consume
                            {queue : Name of the queue to consume from}
                            {--handler= : FQCN of the message handler}
                            {--connection= : Connection name from config/amqp.php}
                            {--exchange= : Override the exchange name}
                            {--exchange-type= : Override the exchange type}
                            {--routing-key=* : Routing key(s) to bind the queue to (repeatable)}
                            {--max-messages=1 : Number of messages to process before exiting}
                            {--all : Keep consuming until the queue is empty (overrides --max-messages)}
                            {--prefetch-count= : Number of messages to prefetch}
                            {--timeout=0 : Per-wait timeout in seconds passed to the broker}
                            {--requeue-on-error : Requeue messages whose handler throws}';

    /** @var string */
    protected $description = 'Consume a finite number of messages from an AMQP queue using a handler.';

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
        $queue = (string) $this->argument('queue');
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

        $maxMessages = $this->option('all') ? PHP_INT_MAX : max(1, (int) $this->option('max-messages'));
        $requeueOnError = (bool) $this->option('requeue-on-error');

        $properties = $this->buildProperties();
        // For a one-shot consume, persistent=false makes the broker tell us
        // when the queue is empty — and --all relies on that signal to exit.
        $properties['persistent'] = false;

        $stopped = false;

        $callback = function (AMQPMessage $message, $resolverConsumer) use (
            $callable,
            $maxMessages,
            $requeueOnError,
            &$stopped
        ) {
            if ($stopped || ($maxMessages < PHP_INT_MAX && ($this->processed + $this->failed) >= $maxMessages)) {
                return;
            }

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

            if (($this->processed + $this->failed) >= $maxMessages
                && is_object($resolverConsumer)
                && method_exists($resolverConsumer, 'stopWhenProcessed')
            ) {
                $resolverConsumer->stopWhenProcessed();
                $stopped = true;
            }
        };

        $this->info(sprintf('Consuming up to %s message(s) from [%s]...',
            $maxMessages === PHP_INT_MAX ? 'all' : $maxMessages,
            $queue
        ));

        try {
            $this->amqp->consume($queue, $callback, $properties);
        } catch (Throwable $e) {
            $this->error('Consumer error: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf('Done. Processed: %d, failed: %d.', $this->processed, $this->failed));

        return $this->failed > 0 && $this->processed === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildProperties(): array
    {
        $props = [];

        if ($connection = $this->option('connection')) {
            $props['use'] = (string) $connection;
        }
        if ($exchange = $this->option('exchange')) {
            $props['exchange'] = (string) $exchange;
        }
        if ($exchangeType = $this->option('exchange-type')) {
            $props['exchange_type'] = (string) $exchangeType;
        }

        $routingKeys = array_values(array_filter((array) $this->option('routing-key'), function ($v) {
            return $v !== null && $v !== '';
        }));
        if (!empty($routingKeys)) {
            $props['routing'] = count($routingKeys) === 1 ? $routingKeys[0] : $routingKeys;
        }

        $prefetch = $this->option('prefetch-count');
        if ($prefetch !== null && $prefetch !== '') {
            $props['qos'] = true;
            $props['qos_prefetch_count'] = (int) $prefetch;
        }

        $timeout = $this->option('timeout');
        if ($timeout !== null && $timeout !== '') {
            $props['timeout'] = (int) $timeout;
        }

        return $props;
    }
}
