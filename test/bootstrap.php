<?php

/**
 * PHPUnit bootstrap for the laravel-amqp test suite.
 *
 * Loads composer autoload and registers minimal Laravel helper shims so that
 * service providers and other code paths which expect a fully booted Laravel
 * application can be exercised in isolation.
 */
require __DIR__.'/../vendor/autoload.php';

if (!function_exists('config_path')) {
    /**
     * Lightweight replacement for Laravel's config_path() helper.
     *
     * Tests use this only to satisfy AmqpServiceProvider::boot()'s
     * publishes() registration call - the returned path is never written
     * to during the suite.
     */
    function config_path(string $path = ''): string
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-amqp-test-config';

        if (!is_dir($base)) {
            @mkdir($base, 0777, true);
        }

        return $path === '' ? $base : $base.DIRECTORY_SEPARATOR.ltrim($path, '/\\');
    }
}
