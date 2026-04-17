# Management API

## Queue Statistics

Get queue information:

```php
use Bschmitt\Amqp\Facades\Amqp;

$amqp = app('Amqp');
$stats = $amqp->getQueueStats('my-queue', '/');

// Returns:
// [
//     'messages' => 10,
//     'consumers' => 2,
//     'message_bytes' => 1024,
//     ...
// ]
```

## Connection Information

```php
// Get all connections
$amqp = app('Amqp');
$connections = $amqp->getConnections();

// Get specific connection
$connection = $amqp->getConnections('connection-name');
```

## Channel Information

```php
// Get all channels
$amqp = app('Amqp');
$channels = $amqp->getChannels();

// Get specific channel
$channel = $amqp->getChannels('channel-name');
```

## Node Information

```php
// Get all nodes
$amqp = app('Amqp');
$nodes = $amqp->getNodes();

// Get specific node
$node = $amqp->getNodes('node-name');
```

## Policy Management

### Create Policy

```php
$amqp = app('Amqp');
$amqp->createPolicy('my-policy', [
    'pattern' => '^my-queue$',
    'definition' => [
        'max-length' => 1000,
        'max-length-bytes' => 1048576,
    ]
], '/');
```

### Update Policy

```php
$amqp = app('Amqp');
$amqp->updatePolicy('my-policy', [
    'pattern' => '^my-queue$',
    'definition' => [
        'max-length' => 2000,
    ]
], '/');
```

### Delete Policy

```php
$amqp = app('Amqp');
$amqp->deletePolicy('my-policy', '/');
```

### List Policies

```php
$amqp = app('Amqp');
$policies = $amqp->getPolicies();
```

## Feature Flags

```php
// List all feature flags
$amqp = app('Amqp');
$flags = $amqp->listFeatureFlags();

// Get specific feature flag
$flag = $amqp->getFeatureFlag('quorum_queue');
```
