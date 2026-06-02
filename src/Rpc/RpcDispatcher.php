<?php

namespace Bschmitt\Amqp\Rpc;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Events\RpcCallCompleted;
use Bschmitt\Amqp\Events\RpcCallFailed;
use Bschmitt\Amqp\Events\RpcCallStarted;
use Bschmitt\Amqp\Support\EventDispatcher;
use Bschmitt\Amqp\Support\InteropEnvelope;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Client + server entry point for the gRPC-lite layer.
 *
 * Client side ({@see call()}):
 *  - Resolves the target service's queue and routing key.
 *  - JSON-encodes the request DTO with metadata headers
 *    (`x-rpc-service`, `x-rpc-request`).
 *  - Issues a synchronous RPC call via {@see Amqp::rpc()}.
 *  - Hydrates the response into the request's `responseClass()` (when
 *    declared) or returns the raw decoded array.
 *
 * Server side ({@see serve()}):
 *  - Looks up the handler instance for the registered service.
 *  - Dispatches inbound messages to the right method based on
 *    `x-rpc-request` (falls back to the registered method map).
 *  - Wraps exceptions in an `_rpc_error` envelope so the client raises
 *    {@see RpcException}.
 */
class RpcDispatcher
{
    /** @var Amqp */
    protected $amqp;

    /**
     * Registered server-side handlers, keyed by service FQCN.
     *
     * @var array<class-string<RpcService>, object>
     */
    protected $handlers = [];

    /** @var int */
    protected $defaultTimeout = 30;

    /** @var ServiceRegistry|null */
    protected $registry;

    /**
     * @param Amqp $amqp
     */
    public function __construct(Amqp $amqp)
    {
        $this->amqp = $amqp;
    }

    /**
     * Lazy {@see ServiceRegistry} accessor.
     *
     * @return ServiceRegistry
     */
    public function services(): ServiceRegistry
    {
        if ($this->registry === null) {
            $this->registry = new ServiceRegistry();
        }

        return $this->registry;
    }

    /**
     * Fluent caller for a discovered service.
     *
     * Accepts either a registered alias (`Rpc::service('payments')`) or a
     * full service FQCN (`Rpc::service(PaymentsService::class)`).
     *
     * @param string $serviceNameOrClass
     * @return ServiceCaller
     */
    public function service(string $serviceNameOrClass): ServiceCaller
    {
        if (class_exists($serviceNameOrClass)
            && is_subclass_of($serviceNameOrClass, RpcService::class)) {
            return new ServiceCaller($this, $serviceNameOrClass);
        }

        return new ServiceCaller($this, $this->services()->resolve($serviceNameOrClass));
    }

    /**
     * @param int $seconds
     * @return $this
     */
    public function defaultTimeout(int $seconds): self
    {
        $this->defaultTimeout = max(1, $seconds);

        return $this;
    }

    /**
     * Register a server-side handler for a service.
     *
     * The handler can be:
     *  - an instance whose method names match {@see RpcService::methods()};
     *  - an FQCN that the container can resolve (Laravel only).
     *
     * @param class-string<RpcService> $service
     * @param object|class-string      $handler
     * @return $this
     */
    public function register(string $service, $handler): self
    {
        $this->guardServiceClass($service);

        if (is_string($handler)) {
            $handler = $this->resolveHandler($handler);
        }

        $this->handlers[$service] = $handler;

        return $this;
    }

    /**
     * Make a synchronous RPC call.
     *
     * @param class-string<RpcService> $service
     * @param RpcRequest               $request
     * @param int|null                 $timeoutSeconds
     * @param array<string, mixed>     $properties
     * @return RpcResponse|array<string, mixed>
     *
     * @throws RpcTimeoutException When no reply arrives in `$timeoutSeconds`.
     * @throws RpcException        When the remote handler returned an error.
     */
    public function call(string $service, RpcRequest $request, ?int $timeoutSeconds = null, array $properties = [])
    {
        $this->guardServiceClass($service);

        $timeout = $timeoutSeconds !== null ? max(1, $timeoutSeconds) : $this->defaultTimeout;

        $requestClass = get_class($request);
        $payload = json_encode($request->toPayload());
        if ($payload === false) {
            throw new \InvalidArgumentException('Failed to JSON-encode RPC request');
        }

        $properties = array_merge([
            'queue' => $service::queue(),
            'queue_force_declare' => true,
        ], $properties);

        if ($service::exchange() !== '') {
            $properties['exchange'] = $service::exchange();
        }

        $headers = (array) ($properties['application_headers'] ?? []);
        $headers['x-rpc-service'] = $service;
        $headers['x-rpc-request'] = $requestClass;
        $properties['application_headers'] = $headers;
        $properties['content_type'] = 'application/json';
        $properties['type'] = $service::name().'.'.$this->shortClass($requestClass);

        $correlationId = isset($properties['correlation_id'])
            ? (string) $properties['correlation_id']
            : null;
        $metricKey = $this->metricKey($service, $requestClass);

        EventDispatcher::instance()->dispatch(new RpcCallStarted($service, $requestClass, $correlationId));

        $start = microtime(true);

        try {
            $response = $this->amqp->rpc($service::routingKey(), $payload, $properties, $timeout);
        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000.0;
            $this->amqp->rpcMetrics()->record($metricKey, $durationMs, true);
            EventDispatcher::instance()->dispatch(new RpcCallFailed(
                $service,
                $requestClass,
                $durationMs,
                false,
                get_class($e),
                $e->getMessage(),
                $correlationId
            ));
            throw $e;
        }

        $durationMs = (microtime(true) - $start) * 1000.0;

        if ($response === null) {
            $this->amqp->rpcMetrics()->record($metricKey, $durationMs, true);
            EventDispatcher::instance()->dispatch(new RpcCallFailed(
                $service,
                $requestClass,
                $durationMs,
                true,
                null,
                sprintf('RPC call timed out after %ds', $timeout),
                $correlationId
            ));
            throw new RpcTimeoutException(sprintf(
                'RPC call %s::%s timed out after %ds',
                $service,
                $this->shortClass($requestClass),
                $timeout
            ));
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            // Non-JSON reply — bubble up as a raw string. Treat as success.
            $this->amqp->rpcMetrics()->record($metricKey, $durationMs, false);
            EventDispatcher::instance()->dispatch(new RpcCallCompleted(
                $service,
                $requestClass,
                $durationMs,
                $correlationId
            ));
            return ['_rpc_raw' => $response];
        }

        if (isset($decoded['_rpc_error'])) {
            $errClass = isset($decoded['_rpc_class']) ? (string) $decoded['_rpc_class'] : null;
            $errMessage = (string) $decoded['_rpc_error'];
            $this->amqp->rpcMetrics()->record($metricKey, $durationMs, true);
            EventDispatcher::instance()->dispatch(new RpcCallFailed(
                $service,
                $requestClass,
                $durationMs,
                false,
                $errClass,
                $errMessage,
                $correlationId
            ));
            throw new RpcException($errMessage, $errClass);
        }

        $this->amqp->rpcMetrics()->record($metricKey, $durationMs, false);
        EventDispatcher::instance()->dispatch(new RpcCallCompleted(
            $service,
            $requestClass,
            $durationMs,
            $correlationId
        ));

        return $this->hydrateResponse($requestClass, $decoded);
    }

    /**
     * Start serving a service. Blocks until the consume loop exits.
     *
     * Requires {@see register()} to have been called first (or pass a handler
     * inline via the second arg).
     *
     * @param class-string<RpcService>      $service
     * @param object|class-string|null      $handler Optional handler override.
     * @param array<string, mixed>          $properties
     * @return bool
     */
    public function serve(string $service, $handler = null, array $properties = []): bool
    {
        $this->guardServiceClass($service);

        if ($handler !== null) {
            $this->register($service, $handler);
        }

        if (!isset($this->handlers[$service])) {
            throw new \RuntimeException(sprintf(
                'No handler registered for RPC service [%s]. Call Rpc::register() first.',
                $service
            ));
        }

        $properties = array_merge([
            'queue' => $service::queue(),
            'queue_force_declare' => true,
        ], $properties);

        $dispatcher = $this;
        $serviceClass = $service;

        return $this->amqp->consume($service::queue(), function (AMQPMessage $message, $consumer) use ($dispatcher, $serviceClass) {
            if (!($consumer instanceof Consumer)) {
                throw new \RuntimeException('Rpc::serve() requires the bundled Consumer implementation');
            }

            try {
                $response = $dispatcher->handleRequest($serviceClass, $message);
                $body = json_encode($response);
            } catch (\Throwable $e) {
                $body = json_encode([
                    '_rpc_error' => $e->getMessage(),
                    '_rpc_class' => get_class($e),
                ]);
            }

            $consumer->reply($message, $body, ['content_type' => 'application/json']);
            $consumer->acknowledge($message);
        }, $properties);
    }

    /**
     * Inspect known service handlers (mostly for testing).
     *
     * @return array<class-string<RpcService>, object>
     */
    public function registered(): array
    {
        return $this->handlers;
    }

    /**
     * Dispatch a single inbound request to its handler method.
     *
     * Exposed for testing; production code goes through {@see serve()}.
     *
     * @param class-string<RpcService> $service
     * @param AMQPMessage              $message
     * @return mixed Array form of the response DTO (or raw value).
     */
    public function handleRequest(string $service, AMQPMessage $message)
    {
        if (!isset($this->handlers[$service])) {
            throw new \RuntimeException(sprintf(
                'No handler registered for service [%s]',
                $service
            ));
        }

        $handler = $this->handlers[$service];
        $requestClass = $this->resolveRequestClass($service, $message);
        $method = $this->resolveMethod($service, $requestClass);

        if (!method_exists($handler, $method)) {
            throw new \RuntimeException(sprintf(
                'Handler [%s] is missing method [%s] for request [%s]',
                get_class($handler),
                $method,
                $requestClass
            ));
        }

        $decoded = json_decode((string) $message->body, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        /** @var RpcRequest $request */
        $request = $requestClass::fromPayload($decoded);

        $metricKey = $this->metricKey($service, $requestClass).':serve';
        $start = microtime(true);

        try {
            $result = $handler->{$method}($request);
        } catch (\Throwable $e) {
            $this->amqp->rpcMetrics()->record($metricKey, (microtime(true) - $start) * 1000.0, true);
            throw $e;
        }

        $this->amqp->rpcMetrics()->record($metricKey, (microtime(true) - $start) * 1000.0, false);

        if ($result instanceof RpcResponse) {
            return $result->toPayload();
        }

        if (is_array($result)) {
            return $result;
        }

        return ['result' => $result];
    }

    /**
     * @param class-string<RpcRequest> $requestClass
     * @param array<string, mixed>     $decoded
     * @return RpcResponse|array<string, mixed>
     */
    protected function hydrateResponse(string $requestClass, array $decoded)
    {
        $responseClass = method_exists($requestClass, 'responseClass')
            ? $requestClass::responseClass()
            : null;

        if (is_string($responseClass)
            && $responseClass !== ''
            && is_subclass_of($responseClass, RpcResponse::class)) {
            /** @var RpcResponse $response */
            $response = $responseClass::fromPayload($decoded);

            return $response;
        }

        return $decoded;
    }

    /**
     * @param class-string<RpcService> $service
     * @param AMQPMessage              $message
     * @return class-string<RpcRequest>
     */
    protected function resolveRequestClass(string $service, AMQPMessage $message): string
    {
        $interop = InteropEnvelope::fromMessage($message);
        $headers = $interop->headers;

        if (!empty($headers['x-rpc-request']) && is_string($headers['x-rpc-request'])) {
            $candidate = $headers['x-rpc-request'];
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        $methods = $service::methods();
        if (count($methods) === 1) {
            return (string) array_key_first($methods);
        }

        throw new \RuntimeException(sprintf(
            'Cannot resolve RPC request class for service [%s]: missing x-rpc-request header',
            $service
        ));
    }

    /**
     * @param class-string<RpcService> $service
     * @param class-string<RpcRequest> $requestClass
     * @return string
     */
    protected function resolveMethod(string $service, string $requestClass): string
    {
        $methods = $service::methods();
        if (!isset($methods[$requestClass])) {
            throw new \RuntimeException(sprintf(
                'Service [%s] does not declare a handler for request [%s]',
                $service,
                $requestClass
            ));
        }

        return (string) $methods[$requestClass];
    }

    /**
     * @param class-string $handlerClass
     * @return object
     */
    protected function resolveHandler(string $handlerClass): object
    {
        try {
            $app = \Illuminate\Support\Facades\App::getFacadeApplication();
            if ($app !== null) {
                return $app->make($handlerClass);
            }
        } catch (\Throwable $e) {
            // Fall through to plain instantiation.
        }

        return new $handlerClass();
    }

    /**
     * @param class-string<RpcService> $service
     * @return void
     */
    protected function guardServiceClass(string $service): void
    {
        if (!class_exists($service)) {
            throw new \InvalidArgumentException(sprintf('Service class [%s] does not exist', $service));
        }
        if (!is_subclass_of($service, RpcService::class)) {
            throw new \InvalidArgumentException(sprintf(
                'Service class [%s] must extend %s',
                $service,
                RpcService::class
            ));
        }
    }

    /**
     * Build the key used by {@see \Bschmitt\Amqp\Support\RpcLatencyRecorder}
     * for a `(service, request)` pair. Stripping the namespace keeps
     * dashboards human-readable while staying unique per service.
     *
     * @param class-string<RpcService> $service
     * @param class-string<RpcRequest> $requestClass
     * @return string
     */
    protected function metricKey(string $service, string $requestClass): string
    {
        return $this->shortClass($service).'::'.$this->shortClass($requestClass);
    }

    /**
     * @param string $class
     * @return string
     */
    protected function shortClass(string $class): string
    {
        $parts = explode('\\', $class);

        return (string) end($parts);
    }
}
