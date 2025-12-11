<?php

namespace Bschmitt\Amqp\Models;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Message extends AMQPMessage
{
    /**
     * Set message priority
     *
     * @param int $priority Priority value (0-255)
     * @return $this
     */
    public function setPriority(int $priority): self
    {
        // Clamp priority to valid range
        $priority = max(0, min(255, $priority));
        $this->set('priority', $priority);
        return $this;
    }

    /**
     * Get message priority
     *
     * @return int|null
     */
    public function getPriority(): ?int
    {
        $properties = $this->get_properties();
        return $properties['priority'] ?? null;
    }

    /**
     * Set correlation ID
     *
     * @param string $correlationId Correlation ID
     * @return $this
     */
    public function setCorrelationId(string $correlationId): self
    {
        $this->set('correlation_id', $correlationId);
        return $this;
    }

    /**
     * Get correlation ID
     *
     * @return string|null
     */
    public function getCorrelationId(): ?string
    {
        $properties = $this->get_properties();
        return $properties['correlation_id'] ?? null;
    }

    /**
     * Set reply-to queue/exchange
     *
     * @param string $replyTo Reply-to queue or exchange name
     * @return $this
     */
    public function setReplyTo(string $replyTo): self
    {
        $this->set('reply_to', $replyTo);
        return $this;
    }

    /**
     * Get reply-to
     *
     * @return string|null
     */
    public function getReplyTo(): ?string
    {
        $properties = $this->get_properties();
        return $properties['reply_to'] ?? null;
    }

    /**
     * Set application header
     *
     * @param string $key Header key
     * @param mixed $value Header value
     * @return $this
     */
    public function setHeader(string $key, $value): self
    {
        $properties = $this->get_properties();
        $headers = [];
        
        if (isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable) {
            $headers = $properties['application_headers']->getNativeData();
        }
        
        $headers[$key] = $value;
        $this->set('application_headers', new AMQPTable($headers));
        return $this;
    }

    /**
     * Get application header
     *
     * @param string $key Header key
     * @param mixed $default Default value if header doesn't exist
     * @return mixed
     */
    public function getHeader(string $key, $default = null)
    {
        $properties = $this->get_properties();
        
        if (isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable) {
            $headers = $properties['application_headers']->getNativeData();
            return $headers[$key] ?? $default;
        }
        
        return $default;
    }

    /**
     * Get all application headers
     *
     * @return array
     */
    public function getHeaders(): array
    {
        $properties = $this->get_properties();
        
        if (isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable) {
            return $properties['application_headers']->getNativeData();
        }
        
        return [];
    }

    /**
     * Set multiple application headers
     *
     * @param array $headers Headers array
     * @return $this
     */
    public function setHeaders(array $headers): self
    {
        $properties = $this->get_properties();
        $existingHeaders = [];
        
        if (isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable) {
            $existingHeaders = $properties['application_headers']->getNativeData();
        }
        
        $mergedHeaders = array_merge($existingHeaders, $headers);
        $this->set('application_headers', new AMQPTable($mergedHeaders));
        return $this;
    }

    /**
     * Remove application header
     *
     * @param string $key Header key
     * @return $this
     */
    public function removeHeader(string $key): self
    {
        $properties = $this->get_properties();
        
        if (isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable) {
            $headers = $properties['application_headers']->getNativeData();
            unset($headers[$key]);
            
            if (empty($headers)) {
                $this->set('application_headers', null);
            } else {
                $this->set('application_headers', new AMQPTable($headers));
            }
        }
        
        return $this;
    }
}

