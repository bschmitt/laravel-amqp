<?php

namespace Bschmitt\Amqp\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Lumen Service Provider
 *
 * @author Björn Schmitt <code@bjoern.io>
 */
class LumenServiceProvider extends ServiceProvider
{

    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(\Bschmitt\Amqp\Core\Publisher::class, function ($app) {
            return new \Bschmitt\Amqp\Core\Publisher($app->config);
        });

        $this->app->bind(\Bschmitt\Amqp\Core\Consumer::class, function ($app) {
            return new \Bschmitt\Amqp\Core\Consumer($app->config);
        });

        $this->app->bind('Amqp', \Bschmitt\Amqp\Core\Amqp::class);

        if (!class_exists('Amqp')) {
            class_alias('Bschmitt\Amqp\Facades\Amqp', 'Amqp');
        }
    }
}

