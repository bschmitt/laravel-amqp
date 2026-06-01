<?php

/**
 * Example queue connection for Laravel's native queue worker.
 *
 * Merge the "amqp" entry below into config/queue.php under "connections",
 * or require this file from config/queue.php.
 *
 * Requires config/amqp.php (publish with vendor:publish).
 */
return [
    'amqp' => [
        'driver' => 'amqp',
        // References the key under amqp.properties in config/amqp.php
        'connection' => env('AMQP_ENV', 'production'),
        'queue' => env('AMQP_QUEUE', 'default'),
        'retry_after' => (int) env('AMQP_QUEUE_RETRY_AFTER', 90),
        'block_for' => null,
        'after_commit' => false,
    ],
];
