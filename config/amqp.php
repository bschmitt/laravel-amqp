<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Define which configuration should be used
    |--------------------------------------------------------------------------
    */

    'use' => env('AMQP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | AMQP properties separated by key
    |--------------------------------------------------------------------------
    */

    'properties' => [

        'production' => [
            'host'                  => env('AMQP_HOST', 'localhost'),
            'port'                  => env('AMQP_PORT', 5672),
            'username'              => env('AMQP_USER', ''),
            'password'              => env('AMQP_PASSWORD', ''),
            'vhost'                 => env('AMQP_VHOST', '/'),
            'connect_options'       => [],
            'ssl_options'           => [],

            'exchange'              => 'amq.topic',
            'exchange_type'         => 'topic',
            'exchange_passive'      => false,
            'exchange_durable'      => true,
            'exchange_auto_delete'  => false,
            'exchange_internal'     => false,
            'exchange_nowait'       => false,
            'exchange_properties'   => [
                // 'alternate-exchange' => 'unroutable-exchange',  // Alternate exchange for unroutable messages
            ],

            'queue_force_declare'   => false,
            'queue_passive'         => false,
            'queue_durable'         => true,
            'queue_exclusive'       => false,
            'queue_auto_delete'     => false,
            'queue_nowait'          => false,
            'queue_properties'      => [
                'x-ha-policy' => ['S', 'all'],
                'x-max-length' => 1,
                // 'x-message-ttl' => 60000,        // Message TTL in milliseconds (60 seconds)
                // 'x-expires' => 3600000,          // Queue expiration in milliseconds (1 hour)
                // 'x-dead-letter-exchange' => 'dlx-exchange',  // Dead letter exchange name
                // 'x-dead-letter-routing-key' => 'dlx.key',    // Routing key for dead letters (optional)
                // 'x-max-priority' => 10,                      // Maximum priority level (0-255)
                // 'x-queue-mode' => 'lazy',                    // Queue mode: 'lazy' or 'default' (lazy queues keep messages on disk)
                // 'x-queue-type' => 'quorum',                  // Queue type: 'classic' (default), 'quorum', or 'stream'
                // 'x-queue-master-locator' => 'min-masters',   // Master locator: 'min-masters', 'client-local', or 'random' (deprecated - use quorum queues instead)
            ],

            'consumer_tag'          => '',
            'consumer_no_local'     => false,
            'consumer_no_ack'       => false,
            'consumer_exclusive'    => false,
            'consumer_nowait'       => false,
            'consumer_properties'   => [],
            'timeout'               => 0,
            'persistent'            => false,
            'publish_timeout'       => 0, // Only applicable when a publish is marked as mandatory
            'qos'                   => false,
            'qos_prefetch_size'     => 0,
            'qos_prefetch_count'    => 1,
            'qos_a_global'          => false
        ],

    ],

];
