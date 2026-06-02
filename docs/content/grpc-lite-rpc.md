# gRPC-lite RPC

A typed service-oriented RPC layer that feels like gRPC but ships over RabbitMQ. Added in v3.4.0.

## Why

`Amqp::publish()` is fire-and-forget. `Amqp::rpc()` is synchronous request/reply with raw strings. The gRPC-lite layer adds:

- A shared **service contract** (`RpcService`) describing the queue + method map.
- Typed **request** (`RpcRequest`) and **response** (`RpcResponse`) DTOs with reflection-driven `make()` / `toPayload()`.
- A facade **`Rpc`** with `call()` / `serve()` / `register()` so client and server code stays symmetric and pleasant.

## Class quick-reference

| Class | Purpose |
|-------|---------|
| `Bschmitt\Amqp\Rpc\RpcService` | Abstract base: declares `queue()`, `methods()`, optional `name()` / `exchange()` / `routingKey()` |
| `Bschmitt\Amqp\Rpc\RpcRequest` | Base request DTO with `make()`, optional `responseClass()` |
| `Bschmitt\Amqp\Rpc\RpcResponse` | Base response DTO with `make()` |
| `Bschmitt\Amqp\Rpc\RpcDispatcher` | Coordinates `call()` and `serve()` |
| `Bschmitt\Amqp\Rpc\RpcException` | Thrown when the server returned `_rpc_error` |
| `Bschmitt\Amqp\Rpc\RpcTimeoutException` | Thrown when no reply arrives in time |
| `Bschmitt\Amqp\Facades\Rpc` | Laravel facade; alias `Rpc` is auto-registered |

## Define a service contract

```php
use Bschmitt\Amqp\Rpc\RpcService;
use Bschmitt\Amqp\Rpc\RpcRequest;
use Bschmitt\Amqp\Rpc\RpcResponse;

class UserService extends RpcService
{
    public static function queue(): string
    {
        return 'rpc.user-service';
    }

    public static function methods(): array
    {
        return [
            GetUserRequest::class    => 'getUser',
            CreateUserRequest::class => 'createUser',
        ];
    }
}

class GetUserRequest extends RpcRequest
{
    public $id;

    public function __construct($id = null) { $this->id = $id; }

    public static function responseClass()
    {
        return GetUserResponse::class;
    }
}

class GetUserResponse extends RpcResponse
{
    public $id;
    public $name;

    public function __construct($id = null, $name = null)
    {
        $this->id = $id;
        $this->name = $name;
    }
}
```

PHP 7.3 compatibility is preserved — no typed properties or constructor promotion.

## Client usage

```php
use Rpc;

$response = Rpc::call(
    UserService::class,
    GetUserRequest::make(['id' => 5])
);

// $response is a GetUserResponse — IDE-autocomplete friendly.
echo $response->name;
```

### Configuration

| Method | Purpose |
|--------|---------|
| `Rpc::defaultTimeout(int $seconds)` | Default per-call timeout (default `30`) |
| `Rpc::call(string $service, RpcRequest $request, ?int $timeout = null, array $properties = [])` | Issue a call |
| `Rpc::register(string $service, $handler)` | Register a server-side handler |
| `Rpc::serve(string $service, $handler = null, array $properties = [])` | Start consuming requests |

When the request DTO does **not** declare a `responseClass()`, `call()` returns the raw decoded array.

### Errors

```php
use Bschmitt\Amqp\Rpc\RpcException;
use Bschmitt\Amqp\Rpc\RpcTimeoutException;

try {
    Rpc::call(UserService::class, GetUserRequest::make(['id' => 0]));
} catch (RpcTimeoutException $e) {
    // no reply within timeout
} catch (RpcException $e) {
    // server returned _rpc_error
    $e->remoteClass(); // FQCN of the original exception class on the server
}
```

## Server usage

```php
use Rpc;

class UserServiceHandler
{
    public function getUser(GetUserRequest $request): GetUserResponse
    {
        return GetUserResponse::make([
            'id' => $request->id,
            'name' => 'Ada Lovelace',
        ]);
    }

    public function createUser(CreateUserRequest $request): GetUserResponse
    {
        return GetUserResponse::make(['id' => 99, 'name' => $request->name]);
    }
}

Rpc::register(UserService::class, UserServiceHandler::class)
   ->serve(UserService::class);
```

A handler can be:

- An instance (recommended in tests),
- An FQCN — resolved through the Laravel container when available, otherwise `new $class()`.

Handler return values may be:

- An `RpcResponse` (preferred — flowed through `toPayload()`),
- A plain `array`,
- Anything else (wrapped as `['result' => $value]`).

### Failure semantics

Handler exceptions are caught and serialized as `{"_rpc_error": "...", "_rpc_class": "..."}` so the client raises `RpcException`. The original message is acknowledged either way to prevent retries spinning forever; pair with the existing retry/DLQ topology if you need redelivery semantics.

### Latency tracking & events

Every `Rpc::call()`:

1. Records round-trip time in `Amqp::rpcMetrics()` under `ServiceName::RequestName`.
2. Records server handler time under `ServiceName::RequestName:serve` when using `Rpc::serve()`.
3. Dispatches Laravel events (also available via `EventDispatcher::listen()` outside Laravel):

| Event | When |
|-------|------|
| `RpcCallStarted` | Before the AMQP RPC publish |
| `RpcCallCompleted` | Successful reply (`durationMs` on the event) |
| `RpcCallFailed` | Timeout or remote `_rpc_error` envelope |

```php
use Bschmitt\Amqp\Facades\Amqp;

$stats = Amqp::rpcMetrics()->for('UserService::GetUserRequest');
// ['count' => 12, 'p95_ms' => 9.0, 'error_rate' => 0.08, ...]
```

View in the dashboard: `php artisan amqp:monitor --rpc`.

## Routing & headers

Each `Rpc::call()` adds these AMQP application headers for tracing and dispatch:

| Header | Value |
|--------|-------|
| `x-rpc-service` | FQCN of the service |
| `x-rpc-request` | FQCN of the request DTO |
| `content_type` | `application/json` |
| `type` | `<ServiceName>.<RequestClassName>` |

When a service has exactly one method, the dispatcher will infer the request class even without `x-rpc-request` (useful for non-PHP clients).

## Integration notes

- The dispatcher sits on top of the existing `Amqp::rpc()` primitive — exclusive auto-deleted reply queue, correlation IDs, timeouts.
- Laravel auto-registers the `Rpc` facade alias via `composer.json` `extra.laravel.aliases`.
- Outside Laravel: `$dispatcher = $amqp->rpcDispatcher();` and call methods directly.
- Tests can swap the dispatcher via `Amqp::setRpcDispatcher()` or build their own with `new RpcDispatcher($fakeAmqp)`.

## PHP compatibility

All gRPC-lite classes parse on PHP 7.3 through 8.5.
