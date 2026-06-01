# JSON Schema Validation

The package ships a small, dependency-free JSON Schema validator that
plugs straight into the typed-messaging helpers. Define a schema on your
contract once, and every publish/consume call enforces it for free.

| Component | Role |
|-----------|------|
| `Bschmitt\Amqp\Support\SchemaValidator` | The validator. Subset of JSON Schema Draft 7. No external dependencies. |
| `Bschmitt\Amqp\Exception\SchemaValidationException` | Thrown when validation fails. `errors()` returns one human-readable string per violation. |
| `TypedMessage::schema()` | Override on your contract to declare the schema. |
| `Amqp::publishTyped()` / `publishTypedLater()` / `consumeTyped()` | Automatically run the validator when a contract exposes a non-null schema. |
| `amqp:work --validate-schema` | CLI flag that turns on validation in long-running workers. |

---

## Why a subset?

The full JSON Schema spec is large and full of edge cases (`$ref`, `$id`,
`if`/`then`/`else`, dynamic anchors, …). For message contracts you almost
never need any of that — you need to say "this field is required and must be
a non-empty string in this enum." `SchemaValidator` covers exactly that
common subset without pulling in `justinrainbow/json-schema` and its
sub-dependencies.

If your schemas are intricate enough that the supported subset isn't
sufficient, you can still call your existing validator manually inside the
handler and throw `SchemaValidationException` — the rest of the pipeline
will react identically.

---

## Supported keywords

### Type system

`type` — string or array of strings. Recognised types: `string`, `integer`,
`number`, `boolean`, `null`, `array`, `object`.

> **Note**: `integer` accepts whole-number floats (e.g. `5.0`) but not
> non-integer floats. `boolean` is *not* an `integer`. `array` matches
> sequential lists; non-sequential arrays validate as `object`.

### Object

| Keyword | Notes |
|---------|-------|
| `required` | Array of property names that must exist. |
| `properties` | Map of `name => subschema`. |
| `additionalProperties` | `false` to reject unknown properties; an array to validate them against a subschema. |
| `minProperties` / `maxProperties` | Bounds on the property count. |

### Array

| Keyword | Notes |
|---------|-------|
| `items` | Single subschema applied to every element (tuple-style arrays not supported). |
| `minItems` / `maxItems` | Length bounds. |
| `uniqueItems` | Reject duplicates (scalar values + deep-hashed objects). |

### String

| Keyword | Notes |
|---------|-------|
| `minLength` / `maxLength` | UTF-8 character counts. |
| `pattern` | PCRE regex (delimited automatically). |
| `format` | `email`, `uri`/`url`, `uuid`, `date`, `date-time`, `ipv4`, `ipv6`. Unknown formats are treated as advisory and pass. |

### Number / Integer

| Keyword | Notes |
|---------|-------|
| `minimum` / `maximum` | Inclusive bounds. |
| `exclusiveMinimum` / `exclusiveMaximum` | Numeric exclusive bounds (Draft 6+ style). |
| `multipleOf` | Tolerance is `1e-9` for floating-point safety. |

### Generic

| Keyword | Notes |
|---------|-------|
| `enum` | Value must be one of (deep-equality match). |
| `const` | Value must deep-equal the constant. |

### Composition

| Keyword | Notes |
|---------|-------|
| `allOf` | Every subschema must pass. |
| `anyOf` | At least one subschema must pass. |
| `oneOf` | Exactly one subschema must pass. |
| `not` | Value must *not* satisfy the subschema. |

### Intentionally unsupported

`$ref`, `$id`, `if`/`then`/`else`, `patternProperties`, tuple `items` arrays,
`contains`, `dependencies`, `$schema`. Set them if you like — they're ignored
without warning.

---

## Declaring a schema on a contract

Override the static `schema()` method on any `TypedMessage` subclass:

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

    public static function schema()
    {
        return [
            'type' => 'object',
            'required' => ['orderId', 'total', 'currency'],
            'additionalProperties' => false,
            'properties' => [
                'orderId'  => ['type' => 'string', 'minLength' => 1],
                'total'    => ['type' => 'number', 'minimum' => 0],
                'currency' => ['type' => 'string', 'enum' => ['USD', 'EUR', 'GBP']],
            ],
        ];
    }
}
```

From this point on:

- `Amqp::publishTyped(new OrderCreated(...))` validates outbound payloads
  before sending them to the broker.
- `Amqp::publishTypedLater(...)` validates *before* scheduling the message.
- `Amqp::consumeTyped('queue', OrderCreated::class, ...)` validates inbound
  payloads before calling `fromPayload()` and your callback.
- `amqp:work --contract=App\\Messaging\\OrderCreated --validate-schema`
  validates inside the worker.

---

## Standalone validation

You don't have to use the typed helpers to use the validator. Either resolve
it from the `Amqp` service or instantiate it directly:

```php
$amqp = app('Amqp');
$errors = $amqp->schemaValidator()->validate($payload, OrderCreated::schema());

if (!empty($errors)) {
    Log::warning('Invalid order payload', ['errors' => $errors]);
}
```

```php
use Bschmitt\Amqp\Support\SchemaValidator;

$validator = new SchemaValidator();
$validator->assertValid($payload, $schema); // throws SchemaValidationException on failure
```

`validate()` returns an array of error strings (empty = valid). Each string
contains a JSON-pointer-style path so it's obvious which field failed:

```
/total: required property is missing
/currency: value is not one of the allowed enum entries
```

---

## Handling validation failures

`SchemaValidationException` is a `RuntimeException` carrying the per-field
`errors()` array:

```php
use Bschmitt\Amqp\Exception\SchemaValidationException;

try {
    $amqp->publishTyped($message);
} catch (SchemaValidationException $e) {
    foreach ($e->errors() as $error) {
        Log::warning($error);
    }
    throw $e;
}
```

When schema validation runs inside `consumeTyped()` or `amqp:work`, the
exception propagates out of the handler exactly like any other failure. If
you have `--retry=N` enabled, the retry pipeline kicks in. If
`--validate-schema` is set without `--retry`, the worker rejects the message
(producing a `failed: N` count in the CLI summary).

> **Tip:** Pair `--contract` with `--validate-schema` and a non-zero
> `--retry=N` to get automatic DLQ routing for schema-broken messages —
> they exhaust their retry budget once and then RabbitMQ dead-letters them
> for human triage.

---

## Related Pages

- [Typed Messaging](#typed-messaging) — contracts, serializers, and the
  publishing/consuming helpers that wire validation in.
- [Advanced Features](#advanced) — `RetryHandler` + `DeadLetterTopology`
  to handle validation failures in production.
- [Artisan Commands](#artisan-commands) — `--contract` and
  `--validate-schema` options on `amqp:work`.
