<?php

namespace Bschmitt\Amqp\Console;


use Bschmitt\Amqp\Consumer;
use Bschmitt\Amqp\Facades\Amqp;
use Bschmitt\Amqp\Rpc\RpcHandlerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class RpcWorkerCommand extends WorkerCommand {

    const WORKER_VALIDATE = [
        'procedure' => 'required|string',
        'params'    => 'required|array',
    ];

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'amqp:rpc-worker';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run amqp RPC worker';

    /**
     * @param array $data
     * @return array
     */
    public function exec(array $data) {
        $result = ['result' => null, 'error' => null];
        $validator = $this->validator($data, self::WORKER_VALIDATE);
        if ($validator->fails()) {
            $result['error'] = $validator->messages();
        }
        else {
            if ($rpcClass = $this->getRpcMethod($data['procedure'])) { //todo
                try {
                    $result['result'] = $this->rpcCall(new $rpcClass, $data['params']);
                }
                catch (\Exception $e) {
                    $result['error'] = $e->getMessage();
                }
            }
            else {
                $result['error'] = 'procedure not found';
            }
        }

        return $result;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function getRpcMethod(string $name) {
        return config("amqp.methods.$name");
    }

    /**
     * @param RpcHandlerInterface $class
     * @param array $params
     * @return mixed
     */
    public function rpcCall(RpcHandlerInterface $class, array $params = []) {
        return $class->handle($params);
    }

    /**
     * Execute the console command.
     * Request user supervisor config set.
     */
    public function handle() {
        $publisher = app()->make('Bschmitt\Amqp\Publisher');
        Amqp::consume(
            $publisher->getProperty('rpc_queue'),
            function (AMQPMessage $message, Consumer $consumer) {
                $data = json_decode($message->getBody(), true);
                $result = $this->exec($data);
                $correlationId = $message->has('correlation_id') ? $message->get('correlation_id') : null;
                $consumer->getChannel()->basic_publish(
                    new AMQPMessage(
                        json_encode($result),
                        [
                            'content_type'   => $consumer->getProperty('content_type'),
                            'delivery_mode'  => 1,
                            'correlation_id' => $correlationId,
                        ]
                    ),
                    '',
                    $message->get('reply_to')
                );
            },
            [
                'queue_force_declare' => true,
                'queue_durable'       => true,
                'consumer_no_ack'     => true,
            ]
        );
    }

}