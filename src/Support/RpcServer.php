<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Core\Consumer;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * RPC server helper — consumes requests and publishes replies.
 *
 * The handler receives `(AMQPMessage $request, Consumer $consumer)` and
 * should return the response body (string or serializable). Exceptions are
 * converted to error replies when `$sendErrors` is true.
 */
class RpcServer
{
    /** @var Amqp */
    protected $amqp;

    /** @var bool */
    protected $json = false;

    /** @var bool */
    protected $sendErrors = true;

    /**
     * @param Amqp $amqp
     */
    public function __construct(Amqp $amqp)
    {
        $this->amqp = $amqp;
    }

    /**
     * @param bool $json Decode request / encode response as JSON.
     * @return $this
     */
    public function asJson(bool $json = true): self
    {
        $this->json = $json;

        return $this;
    }

    /**
     * @param bool $sendErrors When false, exceptions propagate (message may be nacked by caller).
     * @return $this
     */
    public function sendErrors(bool $sendErrors = true): self
    {
        $this->sendErrors = $sendErrors;

        return $this;
    }

    /**
     * @param string               $queue
     * @param callable             $handler function (AMQPMessage $request, Consumer $consumer): mixed
     * @param array<string, mixed> $properties
     * @return bool
     */
    public function serve(string $queue, callable $handler, array $properties = []): bool
    {
        $server = $this;

        return $this->amqp->consume($queue, function (AMQPMessage $message, $consumer) use ($handler, $server) {
            if (!($consumer instanceof Consumer)) {
                throw new \RuntimeException('RpcServer requires the bundled Consumer implementation');
            }

            try {
                $request = $server->decodeRequest($message);
                $response = call_user_func($handler, $request, $consumer);
                $body = $server->encodeResponse($response);
                $consumer->reply($message, $body, $server->json ? ['content_type' => 'application/json'] : []);
                $consumer->acknowledge($message);
            } catch (\Throwable $e) {
                if ($server->sendErrors) {
                    $errorBody = $server->json
                        ? json_encode(['error' => $e->getMessage()])
                        : 'error: '.$e->getMessage();
                    $consumer->reply($message, $errorBody, $server->json ? ['content_type' => 'application/json'] : []);
                    $consumer->acknowledge($message);
                } else {
                    throw $e;
                }
            }
        }, $properties);
    }

    /**
     * @param AMQPMessage $message
     * @return mixed
     */
    protected function decodeRequest(AMQPMessage $message)
    {
        if (!$this->json) {
            return $message->body;
        }

        $decoded = json_decode((string) $message->body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $message->body;
    }

    /**
     * @param mixed $response
     * @return string
     */
    protected function encodeResponse($response): string
    {
        if (!$this->json) {
            return is_string($response) ? $response : (string) $response;
        }

        return json_encode($response);
    }
}
