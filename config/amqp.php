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
    | Laravel Pulse integration
    |--------------------------------------------------------------------------
    |
    | When true (default), Bschmitt\Amqp\Pulse\AmqpPulseRecorder auto-subscribes
    | to package events and forwards them to Laravel Pulse. Has no effect when
    | laravel/pulse is not installed — the recorder degrades to a no-op.
    |
    */

    'pulse_integration' => env('AMQP_PULSE_INTEGRATION', false),

    /*
    |--------------------------------------------------------------------------
    | Kubernetes liveness / readiness probes
    |--------------------------------------------------------------------------
    |
    | When `enabled` is true the package registers three HTTP routes:
    |   GET {prefix}/live  — liveness  (HTTP 200 / 503)
    |   GET {prefix}/ready — readiness (HTTP 200 / 503)
    |   GET {prefix}       — combined snapshot
    |
    | `state_file` is the on-disk JSON snapshot written by ConsumerLifecycle
    | for cross-process exec probes (e.g. `php artisan amqp:health`).
    |
    */

    'probes' => [
        'enabled' => env('AMQP_PROBES_ENABLED', false),
        'prefix' => env('AMQP_PROBES_PREFIX', 'amqp/health'),
        'middleware' => [],
        'state_file' => env('AMQP_PROBES_STATE_FILE'),
        'heartbeat_age' => (float) env('AMQP_PROBES_HEARTBEAT_AGE', 60.0),
        'queues' => array_filter(explode(',', (string) env('AMQP_PROBES_QUEUES', ''))),
        'max_backlog' => env('AMQP_PROBES_MAX_BACKLOG'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Autoscaling defaults
    |--------------------------------------------------------------------------
    |
    | Tuneable defaults consumed by `amqp:scale` and the
    | {@see Bschmitt\Amqp\Support\AutoscalingAdvisor} when used programmatically.
    |
    */

    'autoscaling' => [
        'messages_per_consumer' => (int) env('AMQP_AUTOSCALE_PER_CONSUMER', 100),
        'min_replicas' => (int) env('AMQP_AUTOSCALE_MIN', 1),
        'max_replicas' => (int) env('AMQP_AUTOSCALE_MAX', 10),
        'lag_seconds' => (float) env('AMQP_AUTOSCALE_LAG_SECONDS', 30.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-region failover
    |--------------------------------------------------------------------------
    |
    | A comma-separated list of connection keys (matching `properties.*`) and
    | an optional primary region tag. When `primary` is null the package
    | tries to match the local region (LARAVEL_CLOUD_REGION / AWS_REGION) to
    | one of the connection keys.
    |
    */

    'regions' => [
        'enabled' => env('AMQP_MULTI_REGION', false),
        'connections' => array_filter(explode(',', (string) env('AMQP_REGIONS', ''))),
        'primary' => env('AMQP_REGION_PRIMARY'),
        'cooldown_seconds' => (int) env('AMQP_REGION_COOLDOWN', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Cloud / managed hosting integration
    |--------------------------------------------------------------------------
    |
    | When `auto_hydrate` is true the service provider parses AMQP_URL /
    | CLOUDAMQP_URL / RABBITMQ_URL on register() and merges the credentials
    | into the active connection block, leaving explicit user config alone.
    |
    */

    'cloud' => [
        'auto_hydrate' => env('AMQP_CLOUD_AUTO_HYDRATE', true),
    ],

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
            
            // Management HTTP API configuration
            'management_host'       => env('AMQP_MANAGEMENT_HOST', 'http://localhost'),
            'management_port'       => env('AMQP_MANAGEMENT_PORT', 15672),
            'management_username'   => env('AMQP_MANAGEMENT_USER', null), // Falls back to AMQP_USER if not set
            'management_password'   => env('AMQP_MANAGEMENT_PASSWORD', null), // Falls back to AMQP_PASSWORD if not set
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
            'publish_timeout'       => 30, // Timeout for waiting for publisher confirms (seconds)
            'publisher_confirms'     => false, // Enable publisher confirms
            'wait_for_confirms'     => true, // Whether to wait for confirms after publishing
            'qos'                   => false,
            'qos_prefetch_size'     => 0,
            'qos_prefetch_count'    => 1,
            'qos_a_global'          => false
        ],

    ],

];
