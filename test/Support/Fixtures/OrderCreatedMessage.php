<?php

namespace Bschmitt\Amqp\Test\Support\Fixtures;

use Bschmitt\Amqp\Support\TypedMessage;

/**
 * Test fixture exercising every {@see TypedMessage} extension hook:
 * payload mapping, routing key default, exchange default, and JSON schema.
 *
 * Mirrors a realistic "order created" event so the tests double as
 * documentation for how end users should structure their own DTOs.
 */
class OrderCreatedMessage extends TypedMessage
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
