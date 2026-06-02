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
        if (!class_exists('Rpc')) {
            class_alias('Bschmitt\Amqp\Facades\Rpc', 'Rpc');
        }

        $this->publishes([
            __DIR__.'/../../config/amqp.php' => config_path('amqp.php'),
            __DIR__.'/../../config/queue-amqp.php' => config_path('queue-amqp.php'),
        ]);

        $this->registerQueueConnector();
        $this->registerCommands();
    }

    /**
     * Register the package's artisan commands when running in the console.
     *
     * @return void
     */
    protected function registerCommands(): void
    {
        if (!$this->isRunningInConsole()) {
            return;
        }

        if (!method_exists($this, 'commands')) {
            return;
        }

        $this->commands([
            \Bschmitt\Amqp\Console\Commands\AmqpWorkCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpConsumeCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpListenCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpPublishCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpPurgeCommand::class,
        ]);
    }

    /**
     * Register the singleton package event dispatcher used by publish/consume.
     *
     * @return void
     */
    protected function registerEventDispatcher(): void
    {
        $this->app->singleton(\Bschmitt\Amqp\Support\EventDispatcher::class, function () {
            return \Bschmitt\Amqp\Support\EventDispatcher::instance();
        });
    }

    /**
     * `$this->app->runningInConsole()` exists in Laravel but not in the bare
     * Illuminate Container used by some package tests. Probe for it safely.
     *
     * @return bool
     */
    protected function isRunningInConsole(): bool
    {
        if (method_exists($this->app, 'runningInConsole')) {
            return (bool) $this->app->runningInConsole();
        }

        // Fall back to PHP_SAPI detection.
        return in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true);
    }

    /**
     * Register the Laravel Queue "amqp" driver when the queue component is available.
     *
     * @return void
     */
    protected function registerQueueConnector(): void
    {
        if (!$this->app->bound('queue')) {
            return;
        }

        $this->app->resolving('queue', function ($manager) {
            if (!method_exists($manager, 'extend')) {
                return;
            }

            $manager->extend('amqp', function () {
                return new \Bschmitt\Amqp\Queue\AmqpConnector($this->app);
            });
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerEventDispatcher();

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

        // gRPC-lite dispatcher singleton (resolves through Amqp so handlers
        // registered via Rpc::register() survive across requests within a
        // worker process).
        $this->app->singleton(\Bschmitt\Amqp\Rpc\RpcDispatcher::class, function ($app) {
            return $app->make(Amqp::class)->rpcDispatcher();
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
            \Bschmitt\Amqp\Support\EventDispatcher::class,
            \Bschmitt\Amqp\Rpc\RpcDispatcher::class,
        ];
    }
}

