<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Support\RpcCallResult;
use Illuminate\Console\Command;

/**
 * Interactive RPC testing console.
 *
 *   php artisan amqp:rpc users.getUser '{"id":"u-1"}'
 *   php artisan amqp:rpc users.getUser --payload=@./req.json --json
 *   php artisan amqp:rpc users.getUser --raw --timeout=5
 *   php artisan amqp:rpc                                # interactive prompts
 *
 * Builds a one-off request through {@see \Bschmitt\Amqp\Support\RpcClient},
 * waits for the reply, and prints the result with duration / failure status.
 * Stays one-shot rather than a long-running REPL so it never wedges a
 * terminal — re-running the command is the loop.
 */
class AmqpRpcCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:rpc:console
                            {routing? : Routing key of the RPC method}
                            {payload? : Inline JSON payload (or @path to a file)}
                            {--payload= : Same as the positional argument; takes precedence}
                            {--timeout=10 : RPC timeout in seconds}
                            {--raw : Treat payload as raw text (no JSON encoding/decoding)}
                            {--correlation= : Use a specific correlation_id}
                            {--connection= : Override connection key}
                            {--json : Output the full result as JSON}';

    /** @var string */
    protected $description = 'Send a one-off RPC request and print the response (interactive when args are omitted)';

    /**
     * @param Amqp $amqp
     * @return int
     */
    public function handle(Amqp $amqp): int
    {
        $routing = (string) ($this->argument('routing') ?: '');
        if ($routing === '' && $this->input->isInteractive() && method_exists($this, 'ask')) {
            $routing = (string) $this->ask('Routing key');
        }
        if ($routing === '') {
            $this->error('Routing key is required.');
            return self::FAILURE;
        }

        $payloadRaw = (string) ($this->option('payload') ?: $this->argument('payload') ?: '');
        if ($payloadRaw === '' && $this->input->isInteractive() && method_exists($this, 'ask')) {
            $payloadRaw = (string) $this->ask('Request payload (JSON or text)');
        }

        if ($payloadRaw !== '' && $payloadRaw[0] === '@') {
            $path = substr($payloadRaw, 1);
            if (!is_file($path)) {
                $this->error("Payload file [{$path}] does not exist.");
                return self::FAILURE;
            }
            $payloadRaw = (string) file_get_contents($path);
        }

        $raw = (bool) $this->option('raw');
        $timeout = max(1, (int) $this->option('timeout'));
        $correlation = (string) ($this->option('correlation') ?: '');
        $connection = (string) ($this->option('connection') ?: '');

        $properties = [];
        if ($connection !== '') {
            $properties['use'] = $connection;
        }
        if ($correlation !== '') {
            $properties['correlation_id'] = $correlation;
        }

        $client = $amqp->rpcClient($properties);
        if (!$raw) {
            $client->asJson(true);
        }
        $client->timeout($timeout);

        $request = $raw ? $payloadRaw : $this->decodeIfJson($payloadRaw);

        try {
            $result = $client->call($routing, $request, [], $timeout);
        } catch (\Throwable $e) {
            return $this->emitException($e);
        }

        return $this->emitResult($result, $routing);
    }

    /**
     * @param string $raw
     * @return mixed
     */
    protected function decodeIfJson(string $raw)
    {
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $raw;
    }

    /**
     * @param RpcCallResult $result
     * @param string        $routing
     * @return int
     */
    protected function emitResult(RpcCallResult $result, string $routing): int
    {
        $duration = $result->durationMs();
        $payload = [
            'routing' => $routing,
            'correlation_id' => $result->correlationId(),
            'duration_ms' => $duration !== null ? round($duration, 2) : null,
            'timed_out' => $result->timedOut(),
            'failed' => $result->failed(),
            'error_class' => $result->errorClass(),
            'response' => $result->body(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $result->failed() ? self::FAILURE : self::SUCCESS;
        }

        if ($result->timedOut()) {
            $this->error(sprintf('Timed out after %.2f ms', (float) $duration));
            return self::FAILURE;
        }

        $this->line(sprintf(
            'OK — %.2f ms (correlation_id=%s)',
            (float) $duration,
            $result->correlationId() ?: 'auto'
        ));
        $this->line('');
        $this->line('-- response --');
        $response = $result->body();
        if (is_array($response)) {
            $this->line((string) json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif (is_scalar($response) || $response === null) {
            $this->line($response === null ? 'null' : (string) $response);
        } else {
            $this->line((string) json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /**
     * @param \Throwable $e
     * @return int
     */
    protected function emitException(\Throwable $e): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'error' => get_class($e),
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error(get_class($e) . ': ' . $e->getMessage());
        }

        return self::FAILURE;
    }
}
