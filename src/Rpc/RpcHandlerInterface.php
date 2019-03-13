<?php

namespace Bschmitt\Amqp\Rpc;


interface RpcHandlerInterface
{
    /**
     * @param array $params
     * @return mixed
     */
    public function handle(array $params);
}
