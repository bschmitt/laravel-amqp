<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Define which configuration should be used
    |--------------------------------------------------------------------------
    */
    'use' => 'production',

    /*
    |--------------------------------------------------------------------------
    | AMQP key (method name) and methods (class implement Bschmitt\Amqp\Rpc\RpcHandlerInterface)
    |--------------------------------------------------------------------------
    */
    'methods' => [
        'example' => Bschmitt\Amqp\Rpc\ExampleRpc::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | AMQP properties separated by key
    |--------------------------------------------------------------------------
    */
    'properties' => [

        'production' => [
            'host'                  => 'localhost',
            'port'                  => 5672,
            'username'              => '',
            'password'              => '',
            'vhost'                 => '/',
            'connect_options'       => [],
            'ssl_options'           => [],
            'content_type'          => 'application/json',

            'exchange'              => 'amq.topic',
            'exchange_type'         => 'topic',
            'exchange_passive'      => false,
            'exchange_durable'      => true,
            'exchange_auto_delete'  => false,
            'exchange_internal'     => false,
            'exchange_nowait'       => false,
            'exchange_properties'   => [],

            'queue_force_declare'   => false,
            'queue_passive'         => false,
            'queue_durable'         => true,
            'queue_exclusive'       => false,
            'queue_auto_delete'     => false,
            'queue_nowait'          => false,
            'queue_properties'      => ['x-ha-policy' => ['S', 'all']],

            'consumer_tag'          => '',
            'consumer_no_local'     => false,
            'consumer_no_ack'       => false,
            'consumer_exclusive'    => false,
            'consumer_nowait'       => false,
            'timeout'               => 0,
            'persistent'            => true,

            'queue'                 => 'worker',
            'rpc_queue'             => 'rpc-worker',
        ],

    ],

];
