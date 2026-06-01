<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Core\Amqp;
use Illuminate\Console\Command;
use Throwable;

/**
 * Publish a single message from the CLI.
 *
 *   php artisan amqp:publish order.created '{"id":42}' \
 *       --exchange=orders --priority=5 --headers='{"X-Source":"cli"}'
 *
 * Primarily a debugging / smoke-test utility — for production publishing,
 * use the {@see Amqp::publish()} API in application code.
 */
class AmqpPublishCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:publish
                            {routing-key : Routing key to publish with}
                            {--body= : Message body text (required unless --file is set)}
                            {--connection= : Connection name from config/amqp.php}
                            {--exchange= : Override the exchange name}
                            {--exchange-type= : Override the exchange type}
                            {--queue= : Bind/declare this queue before publishing}
                            {--priority= : Message priority (0-255)}
                            {--correlation-id= : correlation_id property}
                            {--reply-to= : reply_to property}
                            {--message-id= : message_id property}
                            {--type= : type property}
                            {--expiration= : expiration property in ms}
                            {--content-type= : content_type property}
                            {--headers= : JSON-encoded application_headers, e.g. {"X-Foo":"bar"}}
                            {--mandatory : Send the message with the mandatory flag}
                            {--file= : Read the message body from this file path instead of the argument}';

    /** @var string */
    protected $description = 'Publish a single message to RabbitMQ from the command line.';

    /** @var Amqp */
    protected $amqp;

    public function __construct(Amqp $amqp)
    {
        parent::__construct();
        $this->amqp = $amqp;
    }

    public function handle(): int
    {
        $routingKey = (string) $this->argument('routing-key');

        if ($filePath = $this->option('file')) {
            if (!is_file($filePath) || !is_readable($filePath)) {
                $this->error(sprintf('Cannot read file [%s].', $filePath));
                return self::INVALID;
            }
            $body = (string) file_get_contents($filePath);
        } elseif ($this->option('body') !== null && $this->option('body') !== '') {
            $body = (string) $this->option('body');
        } else {
            $this->error('Either --body or --file is required.');
            return self::INVALID;
        }

        try {
            $properties = $this->buildProperties();
            $this->amqp->publish($routingKey, $body, $properties);
        } catch (Throwable $e) {
            $this->error('Publish failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Published %d byte(s) to routing key [%s]%s.',
            strlen($body),
            $routingKey,
            isset($properties['exchange']) ? ' on exchange ['.$properties['exchange'].']' : ''
        ));

        return self::SUCCESS;
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
        if ($queue = $this->option('queue')) {
            $props['queue'] = (string) $queue;
        }

        $priority = $this->option('priority');
        if ($priority !== null && $priority !== '') {
            $props['priority'] = (int) $priority;
        }

        foreach (['correlation-id', 'reply-to', 'message-id', 'type', 'expiration', 'content-type'] as $opt) {
            $value = $this->option($opt);
            if ($value !== null && $value !== '') {
                // Map kebab-case CLI options to snake_case message properties.
                $key = str_replace('-', '_', $opt);
                $props[$key] = (string) $value;
            }
        }

        if ($headersJson = $this->option('headers')) {
            $decoded = json_decode((string) $headersJson, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException(sprintf(
                    'Invalid --headers value (expected JSON object): %s',
                    json_last_error_msg()
                ));
            }
            $props['application_headers'] = $decoded;
        }

        if ($this->option('mandatory')) {
            $props['mandatory'] = true;
        }

        return $props;
    }
}
