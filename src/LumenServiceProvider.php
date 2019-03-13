<?php

namespace Bschmitt\Amqp;

use Bschmitt\Amqp\Console\RpcWorkerCommand;
use Bschmitt\Amqp\Console\WorkerCommand;
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
        //
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('Bschmitt\Amqp\Publisher', function ($app) {
            return new Publisher($app->config);
        });

        $this->app->bind('Bschmitt\Amqp\Consumer', function ($app) {
            return new Consumer($app->config);
        });

        $this->app->bind('Amqp', 'Bschmitt\Amqp\Amqp');

        $this->app->singleton('command.worker', function () {
            return new WorkerCommand();
        });
        $this->app->singleton('command.rpc-worker', function () {
            return new RpcWorkerCommand();
        });
        $this->commands('command.worker');
        $this->commands('command.rpc-worker');

        if (!class_exists('Amqp')) {
            class_alias('Bschmitt\Amqp\Facades\Amqp', 'Amqp');
        }
    }
}
