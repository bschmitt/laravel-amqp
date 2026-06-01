<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Core\Amqp;
use Illuminate\Console\Command;
use Throwable;

/**
 * Purge all messages from a queue. Prompts for confirmation unless `--force`
 * is supplied.
 */
class AmqpPurgeCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:purge
                            {queue : Name of the queue to purge}
                            {--connection= : Connection name from config/amqp.php}
                            {--force : Skip the confirmation prompt}';

    /** @var string */
    protected $description = 'Purge all messages from an AMQP queue.';

    /** @var Amqp */
    protected $amqp;

    public function __construct(Amqp $amqp)
    {
        parent::__construct();
        $this->amqp = $amqp;
    }

    public function handle(): int
    {
        $queue = (string) $this->argument('queue');

        if (!$this->option('force')) {
            $confirmed = $this->confirm(sprintf(
                'Are you sure you want to purge ALL messages from queue [%s]?',
                $queue
            ), false);

            if (!$confirmed) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        $properties = [];
        if ($connection = $this->option('connection')) {
            $properties['use'] = (string) $connection;
        }

        try {
            $count = $this->amqp->queuePurge($queue, $properties);
        } catch (Throwable $e) {
            $this->error('Purge failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf('Purged %d message(s) from queue [%s].', $count, $queue));

        return self::SUCCESS;
    }
}
