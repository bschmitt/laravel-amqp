<?php

namespace Bschmitt\Amqp;


class MessageBuilder {

    /**
     * @var string
     */
    protected $event;

    /**
     * @var mixed
     */
    protected $payload;

    /**
     * @return string
     */
    public function getEvent(): string {
        return $this->event;
    }

    /**
     * @param string $event
     * @return MessageBuilder
     */
    public function setEvent(string $event): MessageBuilder {
        $this->event = $event;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getPayload(): mixed {
        return $this->payload;
    }

    /**
     * @param mixed $payload
     * @return MessageBuilder
     */
    public function setPayload(mixed $payload): MessageBuilder {
        $this->payload = $payload;

        return $this;
    }

    /**
     * @return string
     */
    public function getMessage() {
        return json_encode([
            'event'   => $this->getEvent(),
            'payload' => $this->getPayload(),
        ]);
    }
}
