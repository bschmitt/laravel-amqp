<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Console\HandlerResolver;
use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\DeadLetterTopology;
use Bschmitt\Amqp\Support\RetryPolicy;
use Illuminate\Console\Command;
use InvalidArgumentException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Long-running AMQP worker — analogous to `queue:work` but for arbitrary
 * AMQP consumers (not just dispatched Laravel jobs).
 *
 * Reads messages off the given queue and dispatches each one to a
 * user-supplied handler. The handler is expected to acknowledge or reject
 * the message itself; if it returns without doing either, the worker will
 * reject the message (with or without requeue per `--requeue-on-error`).
 *
 * Stops cleanly when any of `--max-messages`, `--max-time`, or
 * `--stop-when-empty` are exhausted, or when the broker closes the channel.
 */
class AmqpWorkCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:work
                            {queue : Name of the queue to consume from}
                            {--handler= : FQCN of the message handler (implements MessageHandlerInterface or is invokable)}
                            {--connection= : Connection name from config/amqp.php to use}
                            {--exchange= : Override the exchange name}
                            {--exchange-type= : Override the exchange type (topic, direct, fanout, headers)}
                            {--routing-key=* : Routing key(s) to bind the queue to (repeatable)}
                            {--prefetch-count= : Number of messages to prefetch (enables QoS)}
                            {--max-messages=0 : Stop after processing N messages (0 = unlimited)}
                            {--max-time=0 : Stop after N seconds (0 = unlimited)}
                            {--memory=128 : Memory limit in MB; worker exits if it grows beyond}
                            {--timeout=0 : Per-message wait timeout passed to the broker (0 = block)}
                            {--stop-when-empty : Exit once the queue has no more messages instead of waiting}
                            {--requeue-on-error : Requeue messages whose handler throws an exception}
                            {--retry= : Enable retry/DLQ abstraction with this many retries (0 disables)}
                            {--retry-delay=1000 : Base delay between retries in milliseconds}
                            {--retry-backoff=fixed : Backoff strategy: fixed|exponential}
                            {--retry-multiplier=2.0 : Multiplier for exponential backoff}
                            {--retry-max-delay=0 : Cap for the computed retry delay in ms (0 = uncapped)}
                            {--retry-jitter=0 : Random jitter added to each retry delay (ms)}
                            {--dlq= : Dead-letter queue name (defaults to {queue}.dlq)}
                            {--declare-topology : Declare work + retry + DLQ queues before consuming}
                            {--quiet-iterations : Suppress per-message log output (only show errors and summary)}';

    /** @var string */
    protected $description = 'Run a long-running AMQP worker that dispatches messages to a handler class.';

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
        $this->resolver = $resolver ?? new HandlerResolver($this->laravel ?? null);
    }

    /**
     * Symfony calls handle() (via Illuminate) — return an integer status code.
     *
     * @return int
     */
    public function handle(): int
    {
        $queue = (string) $this->argument('queue');
        $handlerOpt = $this->option('handler');

        if (!$handlerOpt) {
            $this->error('The --handler option is required. Example: --handler="App\\Messaging\\MyHandler"');
            return self::INVALID;
        }

        // Re-bind the resolver to the live container so handlers get DI.
        if ($this->laravel !== null) {
            $this->resolver = new HandlerResolver($this->laravel);
        }

        try {
            $callable = $this->resolver->resolve($handlerOpt);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $properties = $this->buildProperties();

        try {
            $topology = $this->buildTopology($queue, $properties);
        } catch (InvalidArgumentException $e) {
            $this->error('Invalid retry configuration: '.$e->getMessage());
            return self::INVALID;
        }

        if ($topology !== null) {
            $properties = array_merge($properties, $topology->toWorkProperties());

            if ((bool) $this->option('declare-topology')) {
                try {
                    $this->amqp->declareRetryTopology($topology);
                    $this->info(sprintf(
                        '  Declared retry topology (DLQ: %s, retry delays: %s)',
                        $topology->getDlqQueue(),
                        implode('ms, ', $topology->plannedRetryDelays()).'ms'
                    ));
                } catch (Throwable $e) {
                    $this->error('Failed to declare retry topology: '.$e->getMessage());
                    return self::FAILURE;
                }
            }

            $callable = $this->amqp->retryHandler(
                $callable,
                $topology,
                function ($level, $message, $context = []) {
                    $this->logRetryEvent($level, $message, $context);
                }
            );
        }

        $maxMessages = max(0, (int) $this->option('max-messages'));
        $maxTime = max(0, (int) $this->option('max-time'));
        $memoryLimitBytes = max(1, (int) $this->option('memory')) * 1024 * 1024;
        $requeueOnError = (bool) $this->option('requeue-on-error');
        $quiet = (bool) $this->option('quiet-iterations');
        $startTime = time();

        $this->info(sprintf('Starting amqp:work on queue [%s]...', $queue));
        if (!empty($properties['routing'])) {
            $this->line(sprintf(
                '  Bindings: %s on exchange [%s]',
                is_array($properties['routing']) ? implode(', ', $properties['routing']) : $properties['routing'],
                $properties['exchange'] ?? 'default'
            ));
        }

        $stopped = false;

        $callback = function (AMQPMessage $message, $resolverConsumer) use (
            $callable,
            &$properties,
            $maxMessages,
            $maxTime,
            $memoryLimitBytes,
            $requeueOnError,
            $quiet,
            $startTime,
            &$stopped
        ) {
            if ($stopped) {
                return;
            }

            if ($maxMessages > 0 && ($this->processed + $this->failed) >= $maxMessages) {
                $this->stop($resolverConsumer);
                $stopped = true;
                return;
            }

            try {
                $callable($message, $resolverConsumer);
                $this->processed++;
                if (!$quiet) {
                    $this->line(sprintf(
                        '  [<info>OK</info>] processed message (delivery tag %s)',
                        $message->getDeliveryTag()
                    ));
                }
            } catch (Throwable $e) {
                $this->failed++;
                $this->error(sprintf(
                    '  Handler failed (delivery tag %s): %s',
                    $message->getDeliveryTag(),
                    $e->getMessage()
                ));
                $this->safelyReject($resolverConsumer, $message, $requeueOnError);
            }

            if ($maxMessages > 0 && ($this->processed + $this->failed) >= $maxMessages) {
                $this->info(sprintf('Reached max-messages [%d]; stopping.', $maxMessages));
                $this->stop($resolverConsumer);
                $stopped = true;
                return;
            }

            if ($maxTime > 0 && (time() - $startTime) >= $maxTime) {
                $this->info(sprintf('Reached max-time [%ds]; stopping.', $maxTime));
                $this->stop($resolverConsumer);
                return;
            }

            if (memory_get_usage(true) >= $memoryLimitBytes) {
                $this->warn(sprintf('Memory limit reached; stopping worker.'));
                $this->stop($resolverConsumer);
                return;
            }
        };

        try {
            $this->amqp->consume($queue, $callback, $properties);
        } catch (Throwable $e) {
            $this->error('Worker terminated with error: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'amqp:work exited. Processed: %d, failed: %d.',
            $this->processed,
            $this->failed
        ));

        return $this->failed > 0 && $this->processed === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Stop the consumer loop politely.
     *
     * @param mixed $consumer
     * @return void
     */
    protected function stop($consumer): void
    {
        if (is_object($consumer) && method_exists($consumer, 'stopWhenProcessed')) {
            $consumer->stopWhenProcessed();
        }
    }

    /**
     * Reject a message, swallowing any subsequent broker error so the worker
     * loop doesn't die from a transient channel hiccup.
     *
     * @param mixed       $consumer
     * @param AMQPMessage $message
     * @param bool        $requeue
     * @return void
     */
    protected function safelyReject($consumer, AMQPMessage $message, bool $requeue): void
    {
        if (!is_object($consumer) || !method_exists($consumer, 'reject')) {
            return;
        }
        try {
            $consumer->reject($message, $requeue);
        } catch (Throwable $e) {
            // intentionally ignored — we don't want a failed nack to kill the worker
        }
    }

    /**
     * Translate CLI options into the property bag accepted by Amqp::consume().
     *
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

        $routingKeys = (array) $this->option('routing-key');
        $routingKeys = array_values(array_filter($routingKeys, function ($v) {
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

        // `persistent = true` keeps Amqp::consume() running even when the
        // queue is empty; otherwise the package exits as soon as the
        // initial message_count is zero. Default that to true unless the
        // caller wants the queue-drain behaviour.
        $props['persistent'] = !$this->option('stop-when-empty');

        return $props;
    }

    /**
     * Build a {@see DeadLetterTopology} from `--retry-*`/`--dlq` options.
     *
     * Returns null when retries are disabled (no --retry option or --retry=0).
     *
     * @param string               $queue
     * @param array<string, mixed> $properties
     * @return DeadLetterTopology|null
     */
    protected function buildTopology(string $queue, array $properties): ?DeadLetterTopology
    {
        $retryOption = $this->option('retry');
        if ($retryOption === null || $retryOption === '') {
            return null;
        }

        $maxAttempts = (int) $retryOption;
        if ($maxAttempts < 0) {
            throw new InvalidArgumentException('--retry must be >= 0');
        }

        $strategy = (string) $this->option('retry-backoff');
        $baseDelay = (int) $this->option('retry-delay');
        $multiplier = (float) $this->option('retry-multiplier');
        $maxDelay = (int) $this->option('retry-max-delay');
        $jitter = (int) $this->option('retry-jitter');

        switch ($strategy) {
            case 'fixed':
                $policy = RetryPolicy::fixed($maxAttempts, $baseDelay, $jitter);
                break;
            case 'exponential':
                $policy = RetryPolicy::exponential($maxAttempts, $baseDelay, $multiplier, $maxDelay, $jitter);
                break;
            default:
                throw new InvalidArgumentException(
                    sprintf('Unsupported --retry-backoff "%s" (use fixed or exponential)', $strategy)
                );
        }

        $topology = DeadLetterTopology::for($queue, $policy);

        if (!empty($properties['exchange']) || isset($properties['exchange_type'])) {
            $topology->on(
                (string) ($properties['exchange'] ?? ''),
                (string) ($properties['exchange_type'] ?? 'topic')
            );
        }

        $routing = $properties['routing'] ?? null;
        if (is_array($routing) && !empty($routing)) {
            $topology->withRoutingKey((string) reset($routing));
        } elseif (is_string($routing) && $routing !== '') {
            $topology->withRoutingKey($routing);
        }

        $dlq = $this->option('dlq');
        if (is_string($dlq) && $dlq !== '') {
            $topology->withDlqQueue($dlq);
        }

        return $topology;
    }

    /**
     * Surface log events emitted by {@see \Bschmitt\Amqp\Support\RetryHandler}.
     *
     * @param string               $level   psr/log style level
     * @param string               $message
     * @param array<string, mixed> $context
     */
    protected function logRetryEvent(string $level, string $message, array $context = []): void
    {
        $line = '  [retry] '.$message;

        if (in_array($level, ['emergency', 'alert', 'critical', 'error'], true)) {
            $this->error($line);
            return;
        }

        if ($level === 'warning' || $level === 'notice') {
            $this->warn($line);
            return;
        }

        $this->line($line);
    }
}
