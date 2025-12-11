<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Bschmitt\Amqp\Facades\Amqp;
use Exception;

/**
 * Example RPC Server Command
 * 
 * This command demonstrates how to create an RPC server using the Laravel AMQP package.
 * Run this command in a separate process to handle RPC requests.
 * 
 * Usage:
 *   php artisan amqp:rpc-server service-queue
 * 
 * For production, use Supervisor to manage this process:
 *   [program:rpc-server]
 *   command=php /path/to/artisan amqp:rpc-server service-queue
 *   autostart=true
 *   autorestart=true
 *   user=www-data
 */
class RpcServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amqp:rpc-server 
                            {queue : The queue name to listen on}
                            {--exchange= : Exchange name (default: amq.direct)}
                            {--exchange-type=direct : Exchange type}
                            {--timeout=0 : Timeout in seconds (0 = no timeout)}
                            {--prefetch=10 : Prefetch count for QoS}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start an RPC server to process requests and send replies';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $queue = $this->argument('queue');
        $exchange = $this->option('exchange') ?: 'amq.direct';
        $exchangeType = $this->option('exchange-type');
        $timeout = (int) $this->option('timeout');
        $prefetch = (int) $this->option('prefetch');

        $this->info("Starting RPC server for queue: {$queue}");
        $this->info("Exchange: {$exchange} ({$exchangeType})");
        $this->info("Press Ctrl+C to stop");

        $retryCount = 0;
        $maxRetries = 10;
        $retryDelay = 5;

        while ($retryCount < $maxRetries) {
            try {
                Amqp::consume($queue, function ($message, $resolver) {
                    $this->processRequest($message, $resolver);
                }, [
                    'exchange' => $exchange,
                    'exchange_type' => $exchangeType,
                    'persistent' => true,
                    'timeout' => $timeout,
                    'qos' => true,
                    'qos_prefetch_count' => $prefetch,
                ]);

                // If we get here, consumption stopped normally
                $this->info('RPC server stopped');
                return 0;

            } catch (Exception $e) {
                $retryCount++;
                $this->error("Error: " . $e->getMessage());
                
                if ($retryCount < $maxRetries) {
                    $this->warn("Retrying in {$retryDelay} seconds... (Attempt {$retryCount}/{$maxRetries})");
                    sleep($retryDelay);
                    // Exponential backoff
                    $retryDelay = min($retryDelay * 2, 60);
                } else {
                    $this->error("Max retries reached. Exiting.");
                    return 1;
                }
            }
        }

        return 1;
    }

    /**
     * Process an RPC request and send a reply
     *
     * @param \PhpAmqpLib\Message\AMQPMessage $message
     * @param \Bschmitt\Amqp\Core\Consumer $resolver
     * @return void
     */
    protected function processRequest($message, $resolver)
    {
        try {
            // Get request data
            $requestData = $message->body;
            $properties = $message->get_properties();
            
            // Log the request
            $this->line("Received request: " . substr($requestData, 0, 100));
            
            // Process the request (your business logic here)
            $response = $this->handleRequest($requestData, $properties);
            
            // Send reply using the reply() method
            $result = $resolver->reply($message, $response);
            
            if ($result) {
                $this->info("Reply sent successfully");
            } else {
                $this->error("Failed to send reply");
            }
            
            // Acknowledge the request
            $resolver->acknowledge($message);
            
        } catch (Exception $e) {
            $this->error("Error processing request: " . $e->getMessage());
            
            // Reject the message (optionally requeue)
            $resolver->reject($message, false); // Don't requeue - send to DLX if configured
        }
    }

    /**
     * Handle the actual request processing
     * 
     * Override this method in your own command to implement your business logic
     *
     * @param string $requestData
     * @param array $properties
     * @return mixed
     */
    protected function handleRequest($requestData, array $properties)
    {
        // Example: Echo back the request with a prefix
        return "Response to: " . $requestData;
        
        // Example: Process JSON request
        // $data = json_decode($requestData, true);
        // return json_encode(['status' => 'success', 'data' => $data]);
        
        // Example: Call a service
        // return app(YourService::class)->process($requestData);
    }
}

