<?php

namespace Bschmitt\Amqp\Providers;

use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Amqp;
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
        $this->app->bind('Amqp', Amqp::class);
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
        // Register Configuration Provider
        $this->app->singleton(\Bschmitt\Amqp\Contracts\ConfigurationProviderInterface::class, function ($app) {
            return new \Bschmitt\Amqp\Support\ConfigurationProvider($app['config']);
        });

        // Register Connection Manager
        $this->app->singleton(\Bschmitt\Amqp\Contracts\ConnectionManagerInterface::class, function ($app) {
            $config = $app->make(\Bschmitt\Amqp\Contracts\ConfigurationProviderInterface::class);
            return new \Bschmitt\Amqp\Managers\ConnectionManager($config);
        });

        // Register Batch Manager
        $this->app->singleton(\Bschmitt\Amqp\Contracts\BatchManagerInterface::class, function ($app) {
            return new \Bschmitt\Amqp\Managers\BatchManager();
        });

        // Register Factories
        $this->app->singleton(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class, function ($app) {
            $config = $app->make(\Bschmitt\Amqp\Contracts\ConfigurationProviderInterface::class);
            return new \Bschmitt\Amqp\Factories\PublisherFactory($config);
        });

        $this->app->singleton(\Bschmitt\Amqp\Contracts\ConsumerFactoryInterface::class, function ($app) {
            $config = $app->make(\Bschmitt\Amqp\Contracts\ConfigurationProviderInterface::class);
            return new \Bschmitt\Amqp\Factories\ConsumerFactory($config);
        });

        // Register Message Factory
        $this->app->singleton(\Bschmitt\Amqp\Factories\MessageFactory::class);

        // Register Publisher and Consumer (for backward compatibility)
        $this->app->singleton(\Bschmitt\Amqp\Contracts\PublisherInterface::class, function ($app) {
            $factory = $app->make(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class);
            return $factory->create();
        });

        $this->app->singleton(\Bschmitt\Amqp\Contracts\ConsumerInterface::class, function ($app) {
            $factory = $app->make(\Bschmitt\Amqp\Contracts\ConsumerFactoryInterface::class);
            return $factory->create();
        });

        // Register concrete classes (for backward compatibility)
        $this->app->singleton('Bschmitt\Amqp\Core\Publisher', function ($app) {
            return $app->make(\Bschmitt\Amqp\Contracts\PublisherInterface::class);
        });

        $this->app->singleton('Bschmitt\Amqp\Core\Consumer', function ($app) {
            return $app->make(\Bschmitt\Amqp\Contracts\ConsumerInterface::class);
        });

        // Register Amqp class with all dependencies
        $this->app->singleton(Amqp::class, function ($app) {
            return new Amqp(
                $app->make(\Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class),
                $app->make(\Bschmitt\Amqp\Contracts\ConsumerFactoryInterface::class),
                $app->make(\Bschmitt\Amqp\Factories\MessageFactory::class),
                $app->make(\Bschmitt\Amqp\Contracts\BatchManagerInterface::class)
            );
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            'Amqp',
            Amqp::class,
            \Bschmitt\Amqp\Contracts\PublisherInterface::class,
            \Bschmitt\Amqp\Contracts\ConsumerInterface::class,
            \Bschmitt\Amqp\Contracts\PublisherFactoryInterface::class,
            \Bschmitt\Amqp\Contracts\ConsumerFactoryInterface::class,
            \Bschmitt\Amqp\Contracts\BatchManagerInterface::class,
            'Bschmitt\Amqp\Core\Publisher',
            'Bschmitt\Amqp\Core\Consumer',
        ];
    }
}

