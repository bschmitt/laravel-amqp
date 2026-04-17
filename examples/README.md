# RPC Examples

This directory contains complete, working examples for implementing RPC (Remote Procedure Call) patterns with the Laravel AMQP package.

## Files

### RpcServerCommand.php
A complete Laravel Artisan command that demonstrates how to create an RPC server. This command:
- Processes RPC requests from a queue
- Sends replies using the `reply()` method
- Handles errors and retries
- Can be run with Supervisor for production

**Usage:**
```bash
php artisan amqp:rpc-server service-queue
```

**With Supervisor:**
```ini
[program:rpc-server]
command=php /path/to/artisan amqp:rpc-server service-queue
autostart=true
autorestart=true
user=www-data
```

### RpcClientExample.php
Examples showing how to make RPC calls from your Laravel application:
- Basic RPC calls
- RPC with error handling
- Multiple RPC calls
- RPC with custom properties

**Usage:**
Copy the methods into your controllers or services to make RPC calls.

## Quick Start

1. **Copy the RPC server command to your app:**
   ```bash
   cp examples/RpcServerCommand.php app/Console/Commands/RpcServer.php
   ```

2. **Start the RPC server:**
   ```bash
   php artisan amqp:rpc-server service-queue
   ```

3. **Make RPC calls from your application:**
   ```php
   use Bschmitt\Amqp\Facades\Amqp;
   
   $response = Amqp::rpc('service-queue', 'request-data', [
       'exchange' => 'amq.direct',
       'exchange_type' => 'direct',
   ], 30);
   ```

## Important Notes

- **Separate Processes Required**: RPC server and client must run in separate processes
- **Use Supervisor**: For production, use Supervisor to manage RPC server processes
- **Error Handling**: Always handle timeouts and errors in RPC calls
- **See Documentation**: For complete details, see [RPC Pattern](docs/wiki/RPC-Pattern.md)

## Testing

The RPC functionality is tested in:
- `test/Integration/RpcMethodIntegrationTest.php`
- `test/Integration/ReplyMethodIntegrationTest.php`
- `test/Integration/QueueRpcFunctionTest.php`

Note: Some RPC integration tests may require separate processes due to PHP's single-threaded nature. See the test files for details.

