<?php

namespace Bschmitt\Amqp\Core;

use Illuminate\Contracts\Config\Repository;
use Bschmitt\Amqp\Support\ConfigurationProvider;

/**
 * @author Björn Schmitt <code@bjoern.io>
 * 
 * @deprecated Use ConfigurationProvider directly. This class is kept for backward compatibility.
 */
abstract class Context extends ConfigurationProvider
{
    /**
     * @param Repository $config
     */
    public function __construct(Repository $config)
    {
        parent::__construct($config);
    }

    /**
     * @return mixed
     */
    abstract public function setup();
}


