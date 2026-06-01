# Typed Message Contracts & DTO Serialization

Strings and `json_encode($array)` get you a long way, but as soon as multiple
services share a queue you want one source of truth for what a message looks
like. The typed-messaging helpers let you define each event as a plain PHP
class and have the package handle (de)serialization, content-type setup, and
schema validation for you.

| Component | Role |
|-----------|------|
| `Bschmitt\Amqp\Contracts\MessageContractInterface` | Contract DTOs implement `toPayload()` / `fromPayload()`. |
| `Bschmitt\Amqp\Support\TypedMessage` | Optional base class — reflection-driven default implementations, plus hooks for routing key, exchange, and schema. |
| `Bschmitt\Amqp\Contracts\MessageSerializerInterface` | Wire-format strategy. Default is `JsonMessageSerializer`; swap for MessagePack, Avro, etc. |
| `Bschmitt\Amqp\Support\SchemaValidator` | Optional JSON-Schema-lite validator wired into the publish/consume helpers. |

The high-level helpers on `Amqp` (`publishTyped`, `publishTypedLater`,
`consumeTyped`) tie everything together.

---

## Defining a contract

The simplest contracts extend `TypedMessage` and declare plain public
properties:

```php
namespace App\Messaging;

use Bschmitt\Amqp\Support\TypedMessage;

class OrderCreated extends TypedMessage
{
    /** @var string|null */
    public $orderId;

    /** @var float|null */
    public $total;

    /** @var string|null */
    public $currency;

    public function __construct($orderId = null, $total = null, $currency = null)
    {
        $this->orderId = $orderId;
        $this->total = $total;
        $this->currency = $currency;
    }

    public static function routingKey()
    {
        return 'orders.created';
    }

    public static function exchange()
    {
        return 'shop.events';
    }
}
```

Defaults provided by `TypedMessage`:

- `toPayload()` returns every public property keyed by name (via reflection).
- `fromPayload(array $payload)` instantiates the class without invoking
  the constructor and assigns matching public properties.
- `routingKey()`, `exchange()`, and `schema()` all return `null` unless overridden.

Override any of them when you need different field names, computed fields,
private state, or a constructor with required arguments.

> **PHP 7.3 note:** The class deliberately avoids typed properties,
> constructor property promotion, named arguments, and other PHP 7.4+/8.x-only
> syntax so you can extend it on the lowest supported PHP version.

---

## Publishing typed messages

### `Amqp::publishTyped($contract, $properties = [])`

```php
$amqp = app('Amqp');
$amqp->publishTyped(new OrderCreated('order-1', 19.99, 'USD'));
```

Behaviour:

- Routing key defaults to `OrderCreated::routingKey()`, then
  `$properties['routing']`, then empty.
- Exchange defaults to `OrderCreated::exchange()`, then `$properties['exchange']`.
- `content_type` defaults to the active serializer's content type
  (`application/json` for the default).
- If `OrderCreated::schema()` returns a non-null array the payload is
  validated and a `SchemaValidationException` is thrown on mismatch
  (publish never happens).
- Anything you put in `$properties` (priority, correlation_id,
  application_headers, …) is forwarded to the underlying `publish()` call.

### Override routing on the fly

```php
$amqp->publishTyped(
    new OrderCreated('order-2', 9.99, 'USD'),
    ['routing' => 'orders.created.priority']
);
```

### `Amqp::publishTypedLater($contract, $delayMs, $properties = [])`

Combines [delayed publishing](#delayed-messaging) with the typed pipeline —
schema validation runs *first*, so invalid payloads never even reach the
delay queue:

```php
$amqp->publishTypedLater(new OrderCreated('order-3', 19.99, 'USD'), 30000);
```

---

## Consuming typed messages

### `Amqp::consumeTyped($queue, $contractClass, $callback, $properties = [])`

```php
$amqp = app('Amqp');

$amqp->consumeTyped('orders.queue', OrderCreated::class, function ($order, $message, $resolver) {
    /** @var OrderCreated $order */
    Mail::to($order->orderId)->send(new OrderConfirmation($order));
    $resolver->acknowledge($message);
});
```

The callback signature is `function ($typed, AMQPMessage $message, ConsumerInterface $resolver)`:

- `$typed` is the reconstructed DTO (`$contractClass::fromPayload(decoded)`).
- `$message` is the raw `AMQPMessage` so you still have access to headers,
  properties, and the delivery info.
- `$resolver` is the `ConsumerInterface` for ack/reject/reply.

If `$contractClass::schema()` returns a schema, the payload is validated
*before* `fromPayload()` runs. Validation failures throw
`SchemaValidationException` — let it propagate so [`RetryHandler`](#advanced)
or `--retry`/`--validate-schema` can decide what to do with it.

`consumeTyped()` rejects contract classes that do not implement
`MessageContractInterface` with an `InvalidArgumentException`.

---

## Swapping the serializer

The default is `JsonMessageSerializer` (PHP 7.3-safe; uses
`JSON_THROW_ON_ERROR`, preserves slashes and unicode). Swap it once at boot
time:

```php
use App\Messaging\MessagePackSerializer;

$amqp = app('Amqp');
$amqp->setSerializer(new MessagePackSerializer());
```

Implement `Bschmitt\Amqp\Contracts\MessageSerializerInterface` for custom
formats:

```php
interface MessageSerializerInterface
{
    public function serialize(array $payload): string;
    public function deserialize(string $body): array;
    public function contentType(): string;
}
```

Implementations should throw `InvalidArgumentException` for unprocessable
payloads — the package translates those exceptions into proper publish
failures or consumer retries.

Per-call overrides are not needed; if you need a *different* serializer for
one publish, bypass the typed helpers and call `publish()` directly with a
pre-serialized body and your own `content_type`.

---

## CLI integration: `amqp:work --contract`

`amqp:work` accepts a `--contract` option so workers can opt into the typed
pipeline without writing wrapper code:

```bash
php artisan amqp:work orders.queue \
    --handler="App\\Messaging\\ProcessOrderHandler" \
    --contract="App\\Messaging\\OrderCreated" \
    --validate-schema
```

Your handler can either keep the classic two-argument signature or accept a
third `$typed` argument to receive the DTO:

```php
namespace App\Messaging;

use App\Messaging\OrderCreated;
use Bschmitt\Amqp\Contracts\ConsumerInterface;
use Bschmitt\Amqp\Contracts\MessageHandlerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class ProcessOrderHandler implements MessageHandlerInterface
{
    public function handle(AMQPMessage $message, ConsumerInterface $resolver, $typed = null): void
    {
        /** @var OrderCreated|null $typed */
        if ($typed !== null) {
            $this->orders->markPaid($typed->orderId);
        } else {
            $this->orders->markPaidFromRaw(json_decode($message->body, true));
        }

        $resolver->acknowledge($message);
    }
}
```

Combine `--contract` with `--retry`, `--declare-topology`, and other
`amqp:work` flags exactly as you would for any other handler — schema
validation errors and contract decoding errors flow through the same
`RetryHandler` pipeline as any other handler exception.

| Option | Description |
|--------|-------------|
| `--contract=` | FQCN of a `MessageContractInterface` subclass to deserialize bodies into. |
| `--validate-schema` | If set *and* `Contract::schema()` is non-null, validate inbound payloads and throw before invoking the handler. |

See [Artisan Commands → `amqp:work`](#artisan-commands) for the full option
matrix.

---

## Related Pages

- [Publishing Messages](#publishing) — raw `publish()` API
- [Consuming Messages](#consuming) — raw `consume()` and `consumeTyped()`
- [Schema Validation](#schema-validation) — JSON-Schema-lite reference
- [Delayed Messaging](#delayed-messaging) — `publishTypedLater()`
- [Advanced Features](#advanced) — retry pipeline and DLQ topology
