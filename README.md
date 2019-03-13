# bschmitt/laravel-amqp
AMQP wrapper for Laravel and Lumen to publish and consume messages especially from RabbitMQ

[![Build Status](https://travis-ci.org/bschmitt/laravel-amqp.svg?branch=master)](https://travis-ci.org/bschmitt/laravel-amqp)
[![Latest Stable Version](https://poser.pugx.org/bschmitt/laravel-amqp/v/stable.svg)](https://packagist.org/packages/bschmitt/laravel-amqp)
[![License](https://poser.pugx.org/bschmitt/laravel-amqp/license.svg)](https://packagist.org/packages/bschmitt/laravel-amqp)

## Features
  - Advanced queue configuration
  - Add message to queues easily
  - Listen queues with useful options
  - RPC


## Installation

### Composer

Add the following to your require part within the composer.json: 

```js
"bschmitt/laravel-amqp": "2.*" (Laravel >= 5.5)
"bschmitt/laravel-amqp": "1.*" (Laravel < 5.5)
```
```batch
$ php composer update
```

or

```
$ php composer require bschmitt/laravel-amqp
```

## Integration

### Lumen

Create a **config** folder in the root directory of your Lumen application and copy the content
from **vendor/bschmitt/laravel-amqp/config/amqp.php** to **config/amqp.php**.


Register the Lumen Service Provider in **bootstrap/app.php**:

```php
/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
*/

//...

$app->configure('amqp');
$app->register(Bschmitt\Amqp\LumenServiceProvider::class);

//...
```

Add Facade Support for Lumen 5.2+

```php
//...
$app->withFacades();
class_alias(\Illuminate\Support\Facades\App::class, 'App');
//...
```


### Laravel

Open **config/app.php** and add the service provider and alias:

```php
'Bschmitt\Amqp\AmqpServiceProvider',
```

```php
'Amqp' => 'Bschmitt\Amqp\Facades\Amqp',
```


## Publishing a message

### Push message with routing key

```php
Amqp::publish('routing-key', 'message');
```

### Push message with routing key and create queue

```php	
Amqp::publish('routing-key', 'message' , ['queue' => 'queue-name']);
```

### Push message with routing key and overwrite properties

```php	
Amqp::publish('routing-key', 'message' , ['exchange' => 'amq.direct']);
```

## Consuming messages

### Default worker

```bash
php artisan amqp:worker
```

You can use a standard worker or write your own.

If a standard worker is used, the message must have a specific JSON format.
```json
{
  "event"   : "eventName",
  "payload" : ["a", "b"]
}
```

You can also use the message builder.

```php
$message = new \Bschmitt\Amqp\MessageBuilder();
$message
    ->setEvent('eventName')
    ->setPayload(['a'=>1,'b'=>2]);

$message->getMessage(); // message string
```

When a message is received, the event is ignited. 
You need to register an event in your **EventServiceProvider.php** 
and create a handler file.


```php
//...
protected $listen = [
    'eventName' => [
        'App\Listeners\MyEventListener',
    ]
];
//...
```

### Consume messages, acknowledge and stop when no message is left

```php
Amqp::consume('queue-name', function ($message, $resolver) {
    		
   var_dump($message->body);

   $resolver->acknowledge($message);

   $resolver->stopWhenProcessed();
        
});
```

### Consume messages forever

```php
Amqp::consume('queue-name', function ($message, $resolver) {
    		
   var_dump($message->body);

   $resolver->acknowledge($message);
        
});
```

### Consume messages, with custom settings

```php
Amqp::consume('queue-name', function ($message, $resolver) {
    		
   var_dump($message->body);

   $resolver->acknowledge($message);
      
}, [
	'timeout' => 2,
	'vhost'   => 'vhost3'
]);
```

## RPC

### Call RPC

```php
$result = Amqp::rpc('queue_name', 'message');
```

### Default worker

```bash
php artisan amqp:rpc-worker
```

You can use a standard worker or write your own.

In the case of using a standard worker, the call must have a specific JSON format.
```json
{
  "procedure" : "procedureName",
  "params"    : ["a", "b"]
}
```

You can use the message builder.

```php
$message = new \Bschmitt\Amqp\RpcMessageBuilder();
$message
    ->setProcedure('procedureName')
    ->setParam('a',1)
    ->setParam('b',2);

$message->getMessage(); // message string
```

When a message is received, the handler is called.
You need to register the handler in the **config/amqp.php** 
file and create the handler file itself.


```php
//...
'methods' => [
    'myProcedure' => \App\Rpc\MyHandlerRpc::class,
],
//...
```

The class **\App\Rpc\MyHandlerRpc** must implement the interface **\Bschmitt\Amqp\Rpc\RpcRpcHandlerInterface**


```php
<?php

namespace App\Rpc;


use Bschmitt\Amqp\Rpc\RpcHandlerInterface;

class MyHandlerRpc implements RpcHandlerInterface
{
    public function handle(array $params)
    {
        return 'myResult';
    }
}
```

### Consume rpc message

```php
Amqp::consume(
    'queue_name',
    function (AMQPMessage $message, Consumer $consumer) {
    
        $message->getBody(); //data
        $result = 'result message';
        
        $correlationId = $message->has('correlation_id') ? $message->get('correlation_id') : null;
        $consumer->getChannel()->basic_publish(
            new AMQPMessage(
                $result,
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
```


## Fanout example

### Publishing a message

```php
\Amqp::publish('', 'message' , [
    'exchange_type' => 'fanout',
    'exchange' => 'amq.fanout',
]);
```

### Consuming messages

```php
\Amqp::consume('', function ($message, $resolver) {
    var_dump($message->body);
    $resolver->acknowledge($message);
}, [
    'exchange' => 'amq.fanout',
    'exchange_type' => 'fanout',
    'queue_force_declare' => true,
    'queue_exclusive' => true,
    'persistent' => true// required if you want to listen forever
]);
```

## Credits

* Some concepts were used from https://github.com/mookofe/tail


## License

This package is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
