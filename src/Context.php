<?php namespace Bschmitt\Amqp;

use Illuminate\Config\Repository;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
abstract class Context
{

    const REPOSITORY_KEY = 'amqp';

    /**
     * @var array
     */
    protected $properties = [
        'host' => 'localhost',
        'port' => 5672,
        'username' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
        'persistent' => false,
        
        'exchange' => 'amq.topic',
        'exchange_type' => 'topic',
        'exchange_passive' => false,
        'exchange_durable' => true,
        'exchange_auto_delete' => false,
        'exchange_internal' => false,
        'exchange_nowait' => false,
        'exchange_properties' => [],
        
        'consumer_tag' => '',
        'consumer_no_local' => false,
        'consumer_no_ack' => false,
        'consumer_exclusive' => false,
        'consumer_nowait' => false,
        
        'queue_force_declare' => false,
        'queue_passive' => false,
        'queue_durable' => true,
        'queue_exclusive' => false,
        'queue_auto_delete' => false,
        'queue_nowait' => false,
        
        'queue_properties' => [],
        'connect_options' => [],
        'ssl_options' => [],
        'timeout' => 0,
    ];

    /**
     * Context constructor.
     *
     * @param Repository $config
     */
    public function __construct(Repository $config)
    {
        $this->extractProperties($config);
    }

    /**
     * @param Repository $config
     */
    protected function extractProperties(Repository $config)
    {
        if ($config->has(self::REPOSITORY_KEY)) {
            $data = $config->get(self::REPOSITORY_KEY);
            $this->mergeProperties($data['properties'][$data['use']]);
        }
    }

    /**
     * @param array $properties
     * @return $this
     */
    public function mergeProperties(array $properties)
    {
        $this->properties = array_merge($this->properties, $properties);
        return $this;
    }

    /**
     * @return array
     */
    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getProperty($key)
    {
        return array_key_exists($key, $this->properties) ? $this->properties[$key] : NULL;
    }

    /**
     * @return mixed
     */
    abstract function setup();

}
