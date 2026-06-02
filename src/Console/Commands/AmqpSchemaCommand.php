<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Contracts\MessageContractInterface;
use Bschmitt\Amqp\Contracts\MessageStoreInterface;
use Bschmitt\Amqp\Support\SchemaValidator;
use Illuminate\Console\Command;

/**
 * Schema validation debugger.
 *
 *   php artisan amqp:schema App\\Messages\\OrderCreated
 *       --payload='{"orderId":"o-1","total":9.99}'
 *
 *   php artisan amqp:schema App\\Messages\\OrderCreated
 *       --payload=@./fixtures/orders.json
 *
 *   php artisan amqp:schema App\\Messages\\OrderCreated --message-id=msg_42_xxx
 *
 *   php artisan amqp:schema App\\Messages\\OrderCreated   # interactive prompt
 *
 * The command instantiates the contract through Laravel's container so any
 * constructor dependencies of your typed message resolve correctly. The
 * payload can come from `--payload`, a file (`--payload=@path`), a
 * MessageStore entry (`--message-id=...`), or an interactive prompt.
 *
 * Output annotates each validation error with its JSON-pointer path and
 * the offending value when possible — that's the bit the previous
 * `amqp:work --validate-schema` couldn't do because it ran inside the
 * consume loop.
 */
class AmqpSchemaCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:schema:debug
                            {contract : FQCN of a MessageContractInterface}
                            {--payload= : JSON payload (or @path for a file)}
                            {--message-id= : Pull payload body from the MessageStore by id}
                            {--json : Output the full validation result as JSON}
                            {--show-schema : Also print the contract\'s JSON schema}';

    /** @var string */
    protected $description = 'Validate a payload against a MessageContract and print errors with paths';

    /**
     * @param MessageStoreInterface $store
     * @return int
     */
    public function handle(MessageStoreInterface $store): int
    {
        $class = (string) $this->argument('contract');
        if (!class_exists($class)) {
            return $this->failWith("Contract class [{$class}] does not exist.");
        }

        try {
            $contract = $this->laravel->make($class);
        } catch (\Throwable $e) {
            return $this->failWith('Failed to instantiate contract: ' . $e->getMessage());
        }

        if (!$contract instanceof MessageContractInterface) {
            return $this->failWith(sprintf(
                'Class [%s] must implement %s.',
                $class,
                MessageContractInterface::class
            ));
        }

        // schema() is optional on the contract interface — typed-message
        // implementations expose it either as a static or instance method
        // returning a JSON-Schema-style array.
        if (!method_exists($contract, 'schema') && !method_exists($class, 'schema')) {
            return $this->failWith(sprintf(
                'Class [%s] does not declare a schema() method.',
                $class
            ));
        }

        $schema = method_exists($class, 'schema')
            ? call_user_func([$class, 'schema'])
            : call_user_func([$contract, 'schema']);

        if (!is_array($schema)) {
            return $this->failWith('Contract::schema() must return an array.');
        }

        $payload = $this->resolvePayload($store);
        if ($payload === null) {
            return self::FAILURE;
        }

        $errors = (new SchemaValidator())->validate($payload, $schema);
        $valid = $errors === [];

        $result = [
            'contract' => $class,
            'name' => method_exists($contract, 'name') ? $contract->name() : null,
            'valid' => $valid,
            'errors' => $errors,
        ];

        if ($this->option('show-schema')) {
            $result['schema'] = $schema;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $valid ? self::SUCCESS : self::FAILURE;
        }

        if ($valid) {
            $this->info(sprintf('OK — payload satisfies %s.', $class));
            return self::SUCCESS;
        }

        $this->error(sprintf('Invalid — %d error(s):', count($errors)));
        foreach ($errors as $message) {
            $this->line('  - ' . $message);
        }

        if ($this->option('show-schema')) {
            $this->line('');
            $this->line('-- schema --');
            $this->line((string) json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::FAILURE;
    }

    /**
     * @param MessageStoreInterface $store
     * @return mixed|null Returns null and emits an error when the payload can't be resolved.
     */
    protected function resolvePayload(MessageStoreInterface $store)
    {
        $messageId = (string) ($this->option('message-id') ?: '');
        if ($messageId !== '') {
            $entry = $store->find($messageId);
            if ($entry === null) {
                $this->failWith("Message [{$messageId}] not found in the store.");
                return null;
            }
            return $this->decodeJson((string) ($entry['body'] ?? ''));
        }

        $raw = (string) ($this->option('payload') ?: '');
        if ($raw === '' && $this->input->isInteractive() && method_exists($this, 'ask')) {
            $raw = (string) $this->ask('JSON payload to validate');
        }

        if ($raw === '') {
            $this->failWith('No payload provided. Pass --payload=… , --payload=@path , --message-id=… , or run interactively.');
            return null;
        }

        if ($raw[0] === '@') {
            $path = substr($raw, 1);
            if (!is_file($path)) {
                $this->failWith("Payload file [{$path}] does not exist.");
                return null;
            }
            $raw = (string) file_get_contents($path);
        }

        return $this->decodeJson($raw);
    }

    /**
     * @param string $body
     * @return mixed
     */
    protected function decodeJson(string $body)
    {
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->warn('Payload is not valid JSON — validating against raw string.');
            return $body;
        }

        return $decoded;
    }

    /**
     * @param string $message
     * @return int
     */
    protected function failWith(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['error' => $message], JSON_PRETTY_PRINT));
        } else {
            $this->error($message);
        }
        return self::FAILURE;
    }
}
