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
     * Fluent factory: hydrate a new instance from an associative array.
     *
     *   OrderCreated::make(['orderId' => 'o-1', 'total' => 9.99])
     *
     * @param array<string, mixed> $payload
     * @return static
     */
    public static function make(array $payload = [])
    {
        return static::fromPayload($payload);
    }

    /**
     * Publish this contract through the package's Amqp instance.
     *
     *   OrderCreated::dispatch(['orderId' => 'o-1', 'total' => 9.99]);
     *   OrderCreated::dispatch(['orderId' => 'o-1'], ['exchange' => 'app.events']);
     *
     * Requires a Laravel container so the `Amqp` singleton can be resolved.
     * Outside Laravel, use `Amqp::publishTyped(OrderCreated::make($payload))`.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $properties
     * @return bool|null
     */
    public static function dispatch(array $payload = [], array $properties = [])
    {
        $instance = static::make($payload);

        try {
            $amqp = \Illuminate\Support\Facades\App::make('Amqp');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'OrderCreated::dispatch() requires a Laravel container; '.
                'use $amqp->publishTyped() directly outside Laravel.',
                0,
                $e
            );
        }

        return $amqp->publishTyped($instance, $properties);
    }

    /**
     * Delayed counterpart to {@see dispatch()}.
     *
     * @param array<string, mixed> $payload
     * @param int                  $delayMs
     * @param array<string, mixed> $properties
     * @return bool|null
     */
    public static function dispatchLater(array $payload, int $delayMs, array $properties = [])
    {
        $instance = static::make($payload);

        try {
            $amqp = \Illuminate\Support\Facades\App::make('Amqp');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'TypedMessage::dispatchLater() requires a Laravel container.',
                0,
                $e
            );
        }

        return $amqp->publishTypedLater($instance, $delayMs, $properties);
    }

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
