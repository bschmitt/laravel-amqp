# Kubernetes & Cloud Native

The package ships first-class building blocks for running AMQP consumers
on Kubernetes, Laravel Cloud, Fly.io, Render, and any other managed PaaS:

- **Liveness / readiness probes** — HTTP routes and a CLI exec probe backed
  by the same `HealthState` + `HealthCheck` pair.
- **Consumer autoscaling recommendations** — `AutoscalingAdvisor` +
  `php artisan amqp:scale` (with KEDA trigger output).
- **Laravel Cloud auto-hydration** — `LaravelCloud` detector parses
  `AMQP_URL` / `CLOUDAMQP_URL` / `RABBITMQ_URL` DSNs on register.
- **Multi-region failover** — `MultiRegionConnection` resolves the
  preferred regional connection and cools failed regions down.

All four features are opt-in via `config/amqp.php`.

---

## Liveness / readiness probes

### `HealthState` and `HealthCheck`

`HealthState` is a tiny container the consumer lifecycle stamps with each
state transition. `HealthCheck` combines it with broker connectivity and
optional queue checks into a structured `(ok, checks)` report.

```php
use Bschmitt\Amqp\Support\ConsumerLifecycle;
use Bschmitt\Amqp\Support\HealthState;

$lifecycle = (new ConsumerLifecycle())
    ->withHealth(HealthState::instance(storage_path('framework/amqp.json')))
    ->registerSignalHandlers();

Amqp::consumeWithLifecycle('orders', $handler, $lifecycle);
```

### HTTP routes

```php
// config/amqp.php
'probes' => [
    'enabled' => true,
    'prefix' => 'amqp/health',
    'middleware' => [],
    'state_file' => storage_path('framework/amqp.json'),
    'heartbeat_age' => 60.0,
    'queues' => ['orders', 'orders.dlq'],
    'max_backlog' => 5000,
],
```

| Method | Path                  | Description                                  |
|--------|-----------------------|----------------------------------------------|
| GET    | `/amqp/health/live`   | 200 alive / 503 dead                         |
| GET    | `/amqp/health/ready`  | 200 ready / 503 not ready                    |
| GET    | `/amqp/health/`       | combined snapshot (always 200/503 = live)    |

Each response is a JSON body:

```json
{
  "kind": "readiness",
  "ok": true,
  "checks": [
    {"name": "liveness", "ok": true, "message": "live"},
    {"name": "worker_ready", "ok": true, "message": "consumer marked ready"},
    {"name": "queue:orders", "ok": true, "message": "queue ok (42 msgs)", "context": {"messages": 42}}
  ]
}
```

### CLI exec probe

```bash
php artisan amqp:health                                # readiness (default)
php artisan amqp:health --probe=live --heartbeat-age=30
php artisan amqp:health --queue=orders --backlog=1000
php artisan amqp:health --all --state-file=/var/run/amqp.json
```

Exit codes: `0` healthy, `1` unhealthy — exactly what
`livenessProbe.exec.command` / `readinessProbe.exec.command` expect.

Sample Kubernetes manifest:

```yaml
livenessProbe:
  exec:
    command: ["php", "/var/www/artisan", "amqp:health", "--probe=live",
              "--state-file=/var/run/amqp.json"]
  periodSeconds: 10
readinessProbe:
  exec:
    command: ["php", "/var/www/artisan", "amqp:health", "--probe=ready",
              "--queue=orders", "--backlog=5000",
              "--state-file=/var/run/amqp.json"]
  periodSeconds: 5
```

---

## Consumer autoscaling recommendations

`AutoscalingAdvisor` turns a `QueueMetrics` snapshot into a recommended
replica count plus a KEDA RabbitMQ trigger.

```php
use Bschmitt\Amqp\Support\AutoscalingAdvisor;

$advice = (new AutoscalingAdvisor())
    ->messagesPerConsumer(100)
    ->maxLagSeconds(15.0)
    ->minReplicas(1)
    ->maxReplicas(20)
    ->advise(Amqp::queueMetrics('orders'));
```

Returned shape:

```php
[
    'queue' => 'orders',
    'messages' => 450,
    'lag_seconds' => 12.0,
    'current_consumers' => 2,
    'desired_consumers' => 5,
    'action' => 'scale_up',
    'reasons' => ['depth 450 / 100 msg per consumer -> 5'],
    'keda' => [
        'type' => 'rabbitmq',
        'metadata' => ['queueName' => 'orders', 'vhostName' => '/', 'mode' => 'QueueLength', 'value' => '100'],
        'spec' => ['minReplicaCount' => 1, 'maxReplicaCount' => 20],
    ],
]
```

### CLI

```bash
php artisan amqp:scale orders orders.priority --per-consumer=100
php artisan amqp:scale orders --keda      # emit only the KEDA trigger
php artisan amqp:scale orders --json --fail-on-scale-up
```

Drop the `--keda` output into the `triggers` block of your `ScaledObject`.

---

## Laravel Cloud / managed hosting compatibility

When `amqp.cloud.auto_hydrate` is `true` (default), the service provider
parses any `AMQP_URL` / `CLOUDAMQP_URL` / `RABBITMQ_URL` env var into the
active connection block on `register()`. Explicit user config wins; only
default / blank fields are filled in.

```env
# .env
AMQP_URL=amqps://app:secret@rabbit.cloudamqp.com/%2Fprod
```

Direct API:

```php
use Bschmitt\Amqp\Support\LaravelCloud;

LaravelCloud::isHosted();          // bool
LaravelCloud::region();            // 'us-east-1' | null
LaravelCloud::deploymentId();      // string | null
LaravelCloud::dsn();               // string | null
LaravelCloud::parseDsn($dsn);      // ['host','port','vhost', ...]
```

Recognised environments: Laravel Cloud (`LARAVEL_CLOUD*`),
Vapor (`VAPOR_SSM_PATH`), Forge (`FORGE_SITE_NAME` / `FORGE_REGION`),
Fly.io (`FLY_APP_NAME`), Render (`RENDER_SERVICE_ID`).

---

## Multi-region deployment support

Configure region-scoped connection keys, then ask the resolver to pick or
fail over:

```php
// config/amqp.php
'regions' => [
    'enabled' => true,
    'connections' => ['production-us', 'production-eu', 'production-apac'],
    'primary' => null,             // null → derive from LARAVEL_CLOUD_REGION/AWS_REGION
    'cooldown_seconds' => 30,
],
```

```php
use Bschmitt\Amqp\Support\MultiRegionConnection;

$resolver = app(MultiRegionConnection::class);

$resolver->pick();                 // 'production-us'

$resolver->withFailover(function ($region) use ($payload) {
    Amqp::publish('orders.created', $payload, ['use' => $region]);
});

foreach ($resolver->each() as $region) {
    Amqp::publish('events.maintenance', $payload, ['use' => $region]);
}
```

Failed regions are blacklisted for `cooldown_seconds`. `withFailover()`
re-throws the last broker exception if every available region failed and
throws `RuntimeException("All AMQP regions are currently in cool-down")`
when nothing's eligible.
