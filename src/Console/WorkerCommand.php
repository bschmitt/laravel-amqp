<?php

namespace Bschmitt\Amqp\Console;


use Bschmitt\Amqp\Consumer;
use Illuminate\Support\Facades\Validator;
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Exception\Worker;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Console\Command;


class WorkerCommand extends Command {

    const WORKER_VALIDATE = [
        'event'   => 'required|string',
        'payload' => 'required',
    ];

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'amqp:worker';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run amqp worker';

    /**
     * @param array $data
     * @param array $rules
     * @return Validator\
     */
    public function validator(array $data, array $rules) {
        return Validator::make($data, $rules);
    }

    /**
     * @param array $data
     * @throws Worker
     */
    public function exec(array $data) {
        $validator = $this->validator($data, self::WORKER_VALIDATE);
        if ($validator->fails()) {
            throw new Worker($validator->messages());
        }
        app('events')->fire($data['event'], $data['payload']);
    }

    /**
     * Execute the console command.
     * Request user supervisor config set.
     */
    public function handle() {
        $publisher = app()->make('Bschmitt\Amqp\Publisher');
        Amqp::consume(
            $publisher->getProperty('queue'),
            function (AMQPMessage $message, Consumer $consumer) {
                $this->info($message->getBody(), 'v');
                $this->exec(json_decode($message->getBody(), true));
                $consumer->acknowledge($message);
            },
            [
                'queue_force_declare' => true,
                'queue_durable'       => true,
                'consumer_no_ack'     => true,
            ]
        );
    }

}