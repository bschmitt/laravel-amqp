# SAGA, Events, Middleware & Testing

Added in v3.6.0. These helpers focus on application-level workflows, observability, and test ergonomics.

## SAGA workflow helper

```php
use Bschmitt\Amqp\Facades\Amqp;

$saga = Amqp::saga('checkout')
    ->step('reserveStock', $reserveStock, $releaseStock)
    ->step('chargeCard',  $chargeCard,  $refundCard)
    ->step('shipOrder',   $shipOrder);

$result = $saga->execute(['orderId' => 42]);
```

`Saga` runs steps in order. If any step throws, completed steps' compensations (when registered) run in reverse order. `SagaResult` exposes:

- `succeeded()` / `failed()`
- `getStepResults()`
- `getCompensatedSteps()`
- `getFailedStep()`
- `getException()`

Use `$saga->setLogger($logger)` to receive structured `info`/`error` log events for `saga.<name>.step.start|ok|failed` and `saga.<name>.compensate.*`.

## Laravel events

| Event | When |
|------|------|
| `MessagePublishing` | Right before publish is sent |
| `MessagePublished` | After successful publish |
| `MessageReceived` | At top of the consume pipeline |
| `MessageHandled` | After handler succeeds (includes `durationMs`) |
| `MessageFailed` | When handler throws |

All events live under `Bschmitt\Amqp\Events`. The package dispatches via `\Illuminate\Support\Facades\Event` when available; outside Laravel, register listeners on `Bschmitt\Amqp\Support\EventDispatcher::instance()`.

## Consume middleware pipeline

```php
use Bschmitt\Amqp\Contracts\ConsumeMiddlewareInterface;
use PhpAmqpLib\Message\AMQPMessage;

class LogMiddleware implements ConsumeMiddlewareInterface
{
    public function handle(AMQPMessage $message, callable $next)
    {
        Log::info('received', ['body' => substr($message->body, 0, 200)]);
        $next($message);
    }
}

Amqp::consumeWithMiddleware('orders', $handler, [
    new LogMiddleware(),
    function ($message, $next) {
        $next($message);
    },
]);
```

Middleware order matches registration order; each may short-circuit by skipping `$next`.

## Fake AMQP driver

`Amqp::fake()` swaps the Laravel-bound singleton with `FakeAmqp` (also usable standalone via `new FakeAmqp()`).

```php
$fake = Amqp::fake();

dispatch(new SendWelcomeEmail($user));

$fake->assertPublished('emails.welcome', function ($entry) use ($user) {
    return str_contains($entry['message'], $user->email);
});
$fake->assertPublishedCount(1, 'emails.welcome');
```

Supported assertions:

| Method | Purpose |
|--------|---------|
| `assertPublished($routing, $callback = null)` | At least one publish to `$routing` matches |
| `assertNotPublished($routing)` | No publish to `$routing` |
| `assertNothingPublished()` | No publishes at all |
| `assertPublishedCount($n, $routing = null)` | Exact count globally or per routing key |

`published()` returns the raw record array; `clear()` resets it.

## Async publishing with publisher confirms

`AsyncPublisher` opens a single channel with `confirm_select` and only waits for confirmations on `flush()`:

```php
$async = Amqp::asyncPublisher(['exchange' => 'events'])
    ->onAck(function ($tag)  { /* metric */ })
    ->onNack(function ($tag) { /* metric */ });

foreach ($items as $i) {
    $async->publish('events.item.processed', json_encode($i), [
        'application_headers' => ['x-source' => 'batch'],
    ]);
}

$ok = $async->flush(30); // wait up to 30s for outstanding confirms
$stats = $async->stats(); // ['published' => N, 'acked' => N, 'nacked' => 0, 'pending' => 0]

$async->close();
```

The underlying `Publisher` already supports synchronous publisher confirms via the `publisher_confirms` and `wait_for_confirms` properties; `AsyncPublisher` is the high-throughput layer on top.

## PHP compatibility

All classes here parse on PHP 7.3 through 8.5. Run `php scripts/check-php73-compat.php` to verify.
