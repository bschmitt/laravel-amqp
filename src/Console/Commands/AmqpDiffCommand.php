<?php

namespace Bschmitt\Amqp\Console\Commands;

use Bschmitt\Amqp\Contracts\MessageStoreInterface;
use Bschmitt\Amqp\Support\MessageDiff;
use Illuminate\Console\Command;

/**
 * Diff two messages from the {@see MessageStoreInterface}.
 *
 *   php artisan amqp:diff msg_42_xxx msg_43_yyy
 *   php artisan amqp:diff msg_42_xxx msg_43_yyy --json
 *
 * The diff is rendered with three sections (body, headers, properties).
 * JSON bodies are walked structurally so changes report a JSON pointer
 * path; non-JSON bodies fall back to a line diff.
 */
class AmqpDiffCommand extends Command
{
    /** @var string */
    protected $signature = 'amqp:diff
                            {left : Left-side (older) message id}
                            {right : Right-side (newer) message id}
                            {--json : Emit the full diff structure as JSON}';

    /** @var string */
    protected $description = 'Diff two messages from the MessageStore (body, headers, properties)';

    /**
     * @param MessageStoreInterface $store
     * @return int
     */
    public function handle(MessageStoreInterface $store): int
    {
        $leftId = (string) $this->argument('left');
        $rightId = (string) $this->argument('right');

        $left = $store->find($leftId);
        $right = $store->find($rightId);

        if ($left === null || $right === null) {
            $missing = array_filter([
                $left === null ? $leftId : null,
                $right === null ? $rightId : null,
            ]);
            if ($this->option('json')) {
                $this->line((string) json_encode([
                    'error' => 'not_found',
                    'missing' => array_values($missing),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Message(s) not found: ' . implode(', ', $missing));
            }
            return self::FAILURE;
        }

        $diff = (new MessageDiff())->diff($left, $right);

        if ($this->option('json')) {
            $this->line((string) json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->line((new MessageDiff())->render($diff));

        return self::SUCCESS;
    }
}
