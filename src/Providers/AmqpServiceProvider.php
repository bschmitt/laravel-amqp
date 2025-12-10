<?php

namespace Bschmitt\Amqp\Providers;

use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Publisher;
use Illuminate\Support\ServiceProvider;

class AmqpServiceProvider extends ServiceProvider
{
    
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->bind('Amqp', \Bschmitt\Amqp\Core\Amqp::class);
        if (!class_exists('Amqp')) {
            class_alias('Bschmitt\Amqp\Facades\Amqp', 'Amqp');
        }

        $this->publishes([
            __DIR__.'/../../config/amqp.php' => config_path('amqp.php'),
        ]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(\Bschmitt\Amqp\Contracts\ConfigurationProviderInterface::class, function ($app) {
            return new \Bschmitt\Amqp\Support\ConfigurationProvider($app['config']);
        });

        $this->app->singleton(\Bschmitt\Amqp\Contracts\ConnectionManagerInterface::class, function ($app) {
            $config = $app->make(\Bschmitt\Amqp\Contracts\ConfigurationProviderInterface::class);
            return new \Bschmitt\Amqp\Managers\ConnectionManager($config);
        });

        $this->app->singleton(\Bschmitt\Amqp\Contracts\PublisherInterface::class, function ($app) {
            return new Publisher($app['config']);
        });

        $this->app->singleton(\Bschmitt\Amqp\Contracts\ConsumerInterface::class, function ($app) {
            return new Consumer($app['config']);
        });

        $this->app->singleton('Bschmitt\Amqp\Core\Publisher', function ($app) {
            return $app->make(\Bschmitt\Amqp\Contracts\PublisherInterface::class);
        });

        $this->app->singleton('Bschmitt\Amqp\Core\Consumer', function ($app) {
            return $app->make(\Bschmitt\Amqp\Contracts\ConsumerInterface::class);
        });

        $this->app->singleton(\Bschmitt\Amqp\Factories\MessageFactory::class);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['Amqp', 'Bschmitt\Amqp\Core\Publisher', 'Bschmitt\Amqp\Core\Consumer'];
    }
}

