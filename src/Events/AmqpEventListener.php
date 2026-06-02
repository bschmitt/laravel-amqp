<?php

namespace Bschmitt\Amqp\Events;

use Bschmitt\Amqp\Contracts\ShouldPublishToAmqpInterface;
use Bschmitt\Amqp\Core\Amqp;

/**
 * Wildcard Laravel event listener that bridges marker events to RabbitMQ.
 *
 *   class OrderCreated implements ShouldPublishToAmqpInterface {
 *       public function __construct(public string $orderId) {}
 *   }
 *
 *   event(new OrderCreated('o-1'));   // auto-published as 'order_created'
 *
 * Routing key defaults to the snake-case event class name; exchange
 * defaults to the broker default. Override either by adding
 * `amqpRouting()` / `amqpExchange()` / `amqpPayload()` methods on the event.
 *
 * Register via `Event::listen('*', AmqpEventListener::class.'@dispatch')` —
 * the bundled service provider does this automatically when the application
 * sets `amqp.broadcast_laravel_events => true`.
 */
class AmqpEventListener
{
    /** @var Amqp */
    protected $amqp;

    /**
     * @param Amqp $amqp
     */
    public function __construct(Amqp $amqp)
    {
        $this->amqp = $amqp;
    }

    /**
     * @param string                $eventName
     * @param array<int, mixed>     $payload Standard wildcard listener signature.
     * @return void
     */
    public function dispatch(string $eventName, array $payload = []): void
    {
        $event = $payload[0] ?? null;
        if (!is_object($event) || !($event instanceof ShouldPublishToAmqpInterface)) {
            return;
        }

        $routing = $this->resolveRouting($event);
        $body = $this->resolvePayload($event);
        $properties = $this->resolveProperties($event);

        $this->amqp->publish($routing, $body, $properties);
    }

    /**
     * @param object $event
     * @return string
     */
    protected function resolveRouting(object $event): string
    {
        if (method_exists($event, 'amqpRouting')) {
            $routing = $event->amqpRouting();
            if (is_string($routing) && $routing !== '') {
                return $routing;
            }
        }

        return $this->snakeCase($this->shortClass(get_class($event)));
    }

    /**
     * @param object $event
     * @return string
     */
    protected function resolvePayload(object $event): string
    {
        if (method_exists($event, 'amqpPayload')) {
            $payload = $event->amqpPayload();
            if (is_string($payload)) {
                return $payload;
            }
            return (string) json_encode($payload);
        }

        $payload = [];
        foreach ((new \ReflectionObject($event))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $payload[$property->getName()] = $property->getValue($event);
        }

        return (string) json_encode($payload);
    }

    /**
     * @param object $event
     * @return array<string, mixed>
     */
    protected function resolveProperties(object $event): array
    {
        $properties = ['content_type' => 'application/json'];

        if (method_exists($event, 'amqpExchange')) {
            $exchange = $event->amqpExchange();
            if (is_string($exchange) && $exchange !== '') {
                $properties['exchange'] = $exchange;
            }
        }

        return $properties;
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

    /**
     * @param string $value
     * @return string
     */
    protected function snakeCase(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        return strtolower((string) $value);
    }
}
