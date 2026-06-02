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
        if (!class_exists('Saga')) {
            class_alias('Bschmitt\Amqp\Facades\Saga', 'Saga');
        }

        $this->publishes([
            __DIR__.'/../../config/amqp.php' => config_path('amqp.php'),
            __DIR__.'/../../config/queue-amqp.php' => config_path('queue-amqp.php'),
        ]);

        $this->registerQueueConnector();
        $this->registerCommands();
        $this->registerEventBridge();
        $this->registerPulseRecorder();
        $this->registerHealthProbes();
    }

    /**
     * Register the Kubernetes liveness / readiness HTTP routes when the host
     * application has a router and `amqp.probes.enabled` is true.
     *
     * Routes are intentionally registered as plain closures so the package
     * works on both standard Laravel and Lumen.
     *
     * @return void
     */
    protected function registerHealthProbes(): void
    {
        if (!$this->app->bound('config') || !$this->app->bound('router')) {
            return;
        }
        $config = $this->app->make('config');
        if (!(bool) $config->get('amqp.probes.enabled', false)) {
            return;
        }

        $statePath = $config->get('amqp.probes.state_file');
        if ($statePath !== null && $statePath !== '') {
            \Bschmitt\Amqp\Support\HealthState::instance()->setStatePath((string) $statePath);
            \Bschmitt\Amqp\Support\HealthState::instance()->hydrateFromDisk();
        }

        $router = $this->app->make('router');
        if (!method_exists($router, 'get')) {
            return;
        }

        $prefix = trim((string) $config->get('amqp.probes.prefix', 'amqp/health'), '/');
        $middleware = (array) $config->get('amqp.probes.middleware', []);
        $controller = \Bschmitt\Amqp\Http\Controllers\HealthController::class;

        $group = method_exists($router, 'group')
            ? function (callable $register) use ($router, $prefix, $middleware) {
                $attributes = ['prefix' => $prefix];
                if ($middleware !== []) {
                    $attributes['middleware'] = $middleware;
                }
                $router->group($attributes, $register);
            }
            : function (callable $register) use ($router) { $register($router); };

        $group(function ($r) use ($controller) {
            $r->get('live', [$controller, 'liveness']);
            $r->get('ready', [$controller, 'readiness']);
            $r->get('/', [$controller, 'snapshot']);
        });
    }

    /**
     * Subscribe the {@see \Bschmitt\Amqp\Pulse\AmqpPulseRecorder} to package
     * events so that Laravel Pulse cards can read AMQP metrics out of the box.
     *
     * Silently no-ops when:
     *   - the events dispatcher isn't bound (bare-container tests), or
     *   - `amqp.pulse_integration` is set to `false`.
     *
     * Pulse itself is *not* a hard requirement — the recorder dropouts to a
     * no-op when `laravel/pulse` is missing.
     *
     * @return void
     */
    protected function registerPulseRecorder(): void
    {
        if (!$this->app->bound('events')) {
            return;
        }

        if ($this->app->bound('config')) {
            $enabled = $this->app->make('config')->get('amqp.pulse_integration', true);
            if ($enabled === false) {
                return;
            }
        }

        $this->app->singleton(\Bschmitt\Amqp\Pulse\AmqpPulseRecorder::class);

        $dispatcher = $this->app->make('events');
        if (!method_exists($dispatcher, 'listen')) {
            return;
        }

        $recorder = \Bschmitt\Amqp\Pulse\AmqpPulseRecorder::class;
        $dispatcher->listen(\Bschmitt\Amqp\Events\MessagePublished::class, [$recorder, 'recordPublished']);
        $dispatcher->listen(\Bschmitt\Amqp\Events\MessageHandled::class, [$recorder, 'recordHandled']);
        $dispatcher->listen(\Bschmitt\Amqp\Events\MessageFailed::class, [$recorder, 'recordFailed']);
        $dispatcher->listen(\Bschmitt\Amqp\Events\RpcCallCompleted::class, [$recorder, 'recordRpcCompleted']);
        $dispatcher->listen(\Bschmitt\Amqp\Events\RpcCallFailed::class, [$recorder, 'recordRpcFailed']);
        $dispatcher->listen(\Bschmitt\Amqp\Events\DeadLetterDetected::class, [$recorder, 'recordDeadLetter']);
    }

    /**
     * Auto-publish Laravel events that implement
     * {@see \Bschmitt\Amqp\Contracts\ShouldPublishToAmqpInterface} to AMQP.
     *
     * Disabled by default; enable with `amqp.broadcast_laravel_events => true`.
     *
     * @return void
     */
    protected function registerEventBridge(): void
    {
        if (!$this->app->bound('events') || !$this->app->bound('config')) {
            return;
        }

        $enabled = (bool) $this->app->make('config')->get('amqp.broadcast_laravel_events', false);
        if (!$enabled) {
            return;
        }

        $this->app->make('events')->listen('*', function ($eventName, array $payload) {
            $listener = $this->app->make(\Bschmitt\Amqp\Events\AmqpEventListener::class);
            $listener->dispatch((string) $eventName, $payload);
        });
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
            \Bschmitt\Amqp\Console\Commands\AmqpMonitorCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpDlqCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpTraceCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpExploreCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpReplayCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpInspectCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpDiffCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpSchemaCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpRpcCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpHealthCommand::class,
            \Bschmitt\Amqp\Console\Commands\AmqpScaleCommand::class,
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

        // Pluggable MessageStore. Defaults to the in-memory store; consumers
        // can rebind to a durable implementation in their own provider.
        $this->app->singleton(\Bschmitt\Amqp\Contracts\MessageStoreInterface::class, function () {
            return new \Bschmitt\Amqp\Support\InMemoryMessageStore();
        });

        $this->registerHealthStateAndChecks();
        $this->registerAutoscalingAdvisor();
        $this->registerMultiRegionConnection();
        $this->hydrateFromCloud();
    }

    /**
     * @return void
     */
    protected function registerHealthStateAndChecks(): void
    {
        $this->app->singleton(\Bschmitt\Amqp\Support\HealthState::class, function ($app) {
            $statePath = null;
            if ($app->bound('config')) {
                $cfg = $app->make('config')->get('amqp.probes.state_file');
                if ($cfg !== null && $cfg !== '') {
                    $statePath = (string) $cfg;
                }
            }
            $state = \Bschmitt\Amqp\Support\HealthState::instance($statePath);
            if ($statePath !== null) {
                $state->hydrateFromDisk();
            }
            return $state;
        });

        $this->app->singleton(\Bschmitt\Amqp\Support\HealthCheck::class, function ($app) {
            $state = $app->make(\Bschmitt\Amqp\Support\HealthState::class);
            $amqp = $app->bound(\Bschmitt\Amqp\Core\Amqp::class)
                ? $app->make(\Bschmitt\Amqp\Core\Amqp::class)
                : null;
            $connections = $app->bound(\Bschmitt\Amqp\Contracts\ConnectionManagerInterface::class)
                ? $app->make(\Bschmitt\Amqp\Contracts\ConnectionManagerInterface::class)
                : null;
            $check = new \Bschmitt\Amqp\Support\HealthCheck($state, $amqp, $connections);

            if ($app->bound('config')) {
                $probes = (array) $app->make('config')->get('amqp.probes', []);
                if (!empty($probes['queues']) && is_array($probes['queues'])) {
                    $check->watchQueues($probes['queues']);
                }
                if (isset($probes['heartbeat_age'])) {
                    $check->maxHeartbeatAge((float) $probes['heartbeat_age']);
                }
                if (isset($probes['max_backlog']) && $probes['max_backlog'] !== null && $probes['max_backlog'] !== '') {
                    $check->maxBacklog((int) $probes['max_backlog']);
                }
            }

            return $check;
        });
    }

    /**
     * @return void
     */
    protected function registerAutoscalingAdvisor(): void
    {
        $this->app->singleton(\Bschmitt\Amqp\Support\AutoscalingAdvisor::class, function ($app) {
            $advisor = new \Bschmitt\Amqp\Support\AutoscalingAdvisor();
            if ($app->bound('config')) {
                $cfg = (array) $app->make('config')->get('amqp.autoscaling', []);
                if (isset($cfg['messages_per_consumer'])) {
                    $advisor->messagesPerConsumer((int) $cfg['messages_per_consumer']);
                }
                if (isset($cfg['min_replicas'])) {
                    $advisor->minReplicas((int) $cfg['min_replicas']);
                }
                if (isset($cfg['max_replicas'])) {
                    $advisor->maxReplicas((int) $cfg['max_replicas']);
                }
                if (array_key_exists('lag_seconds', $cfg)) {
                    $advisor->maxLagSeconds($cfg['lag_seconds'] !== null ? (float) $cfg['lag_seconds'] : null);
                }
            }
            return $advisor;
        });
    }

    /**
     * @return void
     */
    protected function registerMultiRegionConnection(): void
    {
        $this->app->singleton(\Bschmitt\Amqp\Support\MultiRegionConnection::class, function ($app) {
            $regions = [];
            $primary = null;
            $cooldown = 30;
            if ($app->bound('config')) {
                $cfg = (array) $app->make('config')->get('amqp.regions', []);
                $regions = (array) ($cfg['connections'] ?? []);
                $primary = isset($cfg['primary']) && $cfg['primary'] !== '' ? (string) $cfg['primary'] : null;
                $cooldown = (int) ($cfg['cooldown_seconds'] ?? 30);
            }
            return new \Bschmitt\Amqp\Support\MultiRegionConnection($regions, $primary, $cooldown);
        });
    }

    /**
     * Opportunistically pull connection details from `AMQP_URL` /
     * `CLOUDAMQP_URL` / `RABBITMQ_URL` so package users on Laravel Cloud /
     * CloudAMQP / Fly / Render get a working connection without rewriting
     * their config.
     *
     * @return void
     */
    protected function hydrateFromCloud(): void
    {
        if (!$this->app->bound('config')) {
            return;
        }
        $config = $this->app->make('config');
        if (!(bool) $config->get('amqp.cloud.auto_hydrate', true)) {
            return;
        }
        $dsn = \Bschmitt\Amqp\Support\LaravelCloud::dsn();
        if ($dsn === null) {
            return;
        }
        $parsed = \Bschmitt\Amqp\Support\LaravelCloud::parseDsn($dsn);
        if ($parsed === []) {
            return;
        }

        $active = (string) $config->get('amqp.use', 'production');
        $existing = (array) $config->get('amqp.properties.' . $active, []);
        $merged = \Bschmitt\Amqp\Support\LaravelCloud::mergeProperties($existing, $parsed);
        $config->set('amqp.properties.' . $active, $merged);
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
            \Bschmitt\Amqp\Contracts\MessageStoreInterface::class,
            \Bschmitt\Amqp\Support\HealthState::class,
            \Bschmitt\Amqp\Support\HealthCheck::class,
            \Bschmitt\Amqp\Support\AutoscalingAdvisor::class,
            \Bschmitt\Amqp\Support\MultiRegionConnection::class,
        ];
    }
}

