<?php

namespace Bschmitt\Amqp\Support;

use Bschmitt\Amqp\Contracts\MessageContractInterface;

/**
 * Optional convenience base class for {@see MessageContractInterface}
 * implementations.
 *
 * Subclasses can either:
 *
 *  - rely on the default reflection-driven `toPayload()`/`fromPayload()`,
 *    which (de)serialise every public property by name; or
 *  - override either method to take full control of the wire format.
 *
 * The class intentionally provides no PHP 8+ syntax (no typed properties, no
 * constructor property promotion) so applications targeting PHP 7.3 can
 * extend it directly.
 *
 * Example:
 * ```
 * class OrderCreated extends TypedMessage
 * {
 *     public $orderId;
 *     public $total;
 *
 *     public function __construct($orderId = null, $total = null) {
 *         $this->orderId = $orderId;
 *         $this->total = $total;
 *     }
 *
 *     public static function routingKey() { return 'orders.created'; }
 *
 *     public static function schema()
 *     {
 *         return [
 *             'type' => 'object',
 *             'required' => ['orderId', 'total'],
 *             'properties' => [
 *                 'orderId' => ['type' => 'string'],
 *                 'total'   => ['type' => 'number', 'minimum' => 0],
 *             ],
 *         ];
 *     }
 * }
 * ```
 */
abstract class TypedMessage implements MessageContractInterface
{
    /**
     * Default payload shape: every public property keyed by name.
     *
     * Override when you need different field names, computed fields, or
     * private/protected state.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [];
        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $payload[$property->getName()] = $property->getValue($this);
        }
        return $payload;
    }

    /**
     * Default reconstruction: instantiate via reflection (no constructor
     * args) and assign matching public properties.
     *
     * Override when your constructor requires arguments or your payload
     * keys do not match property names.
     *
     * @param array<string, mixed> $payload
     * @return static
     */
    public static function fromPayload(array $payload)
    {
        $reflection = new \ReflectionClass(static::class);
        /** @var static $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        foreach ($payload as $key => $value) {
            if ($reflection->hasProperty($key)) {
                $property = $reflection->getProperty($key);
                if ($property->isPublic()) {
                    $property->setValue($instance, $value);
                }
            }
        }

        return $instance;
    }

    /**
     * Optional JSON-Schema-like definition for {@see SchemaValidator}.
     *
     * Return `null` to disable schema validation entirely. Subclasses that
     * want validation should override this.
     *
     * @return array<string, mixed>|null
     */
    public static function schema()
    {
        return null;
    }

    /**
     * Optional default routing key used by `publishTyped()` when the caller
     * does not pass an explicit `routing` property.
     *
     * @return string|null
     */
    public static function routingKey()
    {
        return null;
    }

    /**
     * Optional default exchange used by `publishTyped()` when the caller
     * does not pass an explicit `exchange` property.
     *
     * @return string|null
     */
    public static function exchange()
    {
        return null;
    }
}
