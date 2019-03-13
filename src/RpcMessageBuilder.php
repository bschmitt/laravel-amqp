<?php

namespace Bschmitt\Amqp;


class RpcMessageBuilder {

    /**
     * @var string
     */
    protected $procedure;

    /**
     * @var array
     */
    protected $params;

    /**
     * @return string
     */
    public function getProcedure(): string {
        return $this->procedure;
    }

    /**
     * @param string $procedure
     * @return RpcMessageBuilder
     */
    public function setProcedure(string $procedure): RpcMessageBuilder {
        $this->procedure = $procedure;

        return $this;
    }

    /**
     * @return array
     */
    public function getParams(): array {
        return $this->params;
    }

    /**
     * @param array $params
     * @return RpcMessageBuilder
     */
    public function setParams(array $params): RpcMessageBuilder {
        $this->params = $params;

        return $this;
    }

    /**
     * @param string $name
     * @param $value
     * @return RpcMessageBuilder
     */
    public function setParam(string $name, $value): RpcMessageBuilder {
        $this->params[$name] = $value;

        return $this;
    }

    /**
     * @return string
     */
    public function getMessage() {
        return json_encode([
            'procedure' => $this->getProcedure(),
            'params'    => $this->getParams(),
        ]);
    }
}
