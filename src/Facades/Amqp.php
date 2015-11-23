<?php namespace Bschmitt\Amqp\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Amqp extends Facade
{

    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'Amqp';
    }

}