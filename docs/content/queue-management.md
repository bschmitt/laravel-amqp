# Queue Management

## Queue Operations

### Purge Queue

Remove all messages from a queue without deleting it.

```php
use Bschmitt\Amqp\Facades\Amqp;

$amqp = app('Amqp');
$amqp->queuePurge('my-queue', [
    'queue' => 'my-queue'

]);
```

### Delete Queue

Delete a queue completely.

```php
// Delete queue (only if unused and empty)
$amqp = app('Amqp');
$amqp->queueDelete('my-queue', [
    'queue' => 'my-queue'

], false, false);

// Force delete (even if not empty)
$amqp->queueDelete('my-queue', [
    'queue' => 'my-queue'

], false, false);
```

### Unbind Queue

Remove binding between a queue and an exchange.

```php
$amqp = app('Amqp');
$amqp->queueUnbind('my-queue', 'my-exchange', 'routing-key', null, [
    'queue' => 'my-queue',
    'exchange' => 'my-exchange'
]);
```

## Exchange Operations

### Delete Exchange

```php
$amqp = app('Amqp');
$amqp->exchangeDelete('my-exchange', [
    'exchange' => 'my-exchange'
], false);
```

### Unbind Exchange

```php
$amqp = app('Amqp');
$amqp->exchangeUnbind('destination-exchange', 'source-exchange', 'routing-key', null, [
    'exchange' => 'destination-exchange'
]);
```

## Practical Examples

### Cleanup Script

```php
// app/Console/Commands/CleanupQueues.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Bschmitt\Amqp\Facades\Amqp;

class CleanupQueues extends Command
{

    protected $signature = 'amqp:cleanup {action} {queue}';
    protected $description = 'Cleanup queues';

    public function handle()

    {
        $action = $this->argument('action');
        $queue = $this->argument('queue');


        $properties = ['queue' => $queue];
        $amqp = app('Amqp');


        switch ($action) {
            case 'purge':
                $amqp->queuePurge($queue, $properties);
                $this->info("Queue {$queue} purged");

                break;


            case 'delete':
                $amqp->queueDelete($queue, $properties);
                $this->info("Queue {$queue} deleted");

                break;


            default:
                $this->error("Unknown action: {$action}");

        }

    }
}
```
