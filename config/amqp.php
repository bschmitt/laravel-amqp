<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AMQP Server Connection
    |--------------------------------------------------------------------------
    |
    | The name of your default AMQP server connection. This connection will
    | be used as the default for all queues operations unless a different
    | name is given when performing said operation. This connection name
    | should be listed in the array of connections below.
    |
    */
    'default' => 'production',

    /*
    |--------------------------------------------------------------------------
    | Queues Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [

        'production' => [
            'host'          => 'localhost',
            'port'          => 5672,
            'username'      => 'username',
            'password'      => 'password',
            'vhost'         => '/',
            'exchange'      => 'amq.topic',
            'exchange_type' => 'topic',
            'consumer_tag'  => 'consumer',
        ],

    ],

];