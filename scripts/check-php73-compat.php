<?php

/**
 * Static check that feature-related sources and tests parse as PHP 7.3 syntax.
 *
 * Uses nikic/php-parser (transitive dev dependency) to fail fast on 7.4+
 * syntax: numeric literal separators, arrow functions, typed properties,
 * constructor promotion, union types, match, attributes, etc.
 *
 * Usage:
 *   php scripts/check-php73-compat.php          # curated feature files
 *   php scripts/check-php73-compat-all-src.php    # entire src/ tree
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

$files = [
    // Retry / dead-letter abstractions
    'src/Support/RetryPolicy.php',
    'src/Support/DeadLetterTopology.php',
    'src/Support/RetryHandler.php',
    // Typed messaging + delayed + schema
    'src/Contracts/MessageContractInterface.php',
    'src/Contracts/MessageSerializerInterface.php',
    'src/Support/JsonMessageSerializer.php',
    'src/Support/TypedMessage.php',
    'src/Support/SchemaValidator.php',
    'src/Support/DelayedPublisher.php',
    'src/Support/PublishBackoff.php',
    'src/Exception/SchemaValidationException.php',
    // Touchpoints
    'src/Console/HandlerResolver.php',
    'src/Contracts/MessageHandlerInterface.php',
    'src/Console/Commands/AmqpWorkCommand.php',
    'src/Console/Commands/AmqpPublishCommand.php',
    'src/Core/Amqp.php',
    // Tests
    'test/Support/Fixtures/OrderCreatedMessage.php',
    'test/Unit/RetryPolicyTest.php',
    'test/Unit/DeadLetterTopologyTest.php',
    'test/Unit/RetryHandlerTest.php',
    'test/Unit/AmqpRetryTopologyTest.php',
    'test/Unit/Console/AmqpWorkCommandTest.php',
    'test/Unit/Console/AmqpPublishCommandTest.php',
    'test/Unit/Console/HandlerResolverTest.php',
    'test/Support/Fixtures/TypedRecordingHandler.php',
    'test/Unit/JsonMessageSerializerTest.php',
    'test/Unit/SchemaValidatorTest.php',
    'test/Unit/TypedMessageTest.php',
    'test/Unit/PublishBackoffTest.php',
    'test/Unit/DelayedPublisherTest.php',
    'test/Unit/AmqpTypedMessagingTest.php',
    // Production infrastructure (v3.5)
    'src/Support/QueueProfile.php',
    'src/Support/ExchangeTopology.php',
    'src/Support/CorrelationContext.php',
    'src/Support/TraceContext.php',
    'src/Support/MessageHeaders.php',
    'src/Support/W3cTracePropagator.php',
    'src/Support/NullTracePropagator.php',
    'src/Support/CallbackTracePropagator.php',
    'src/Support/ConsumerLifecycle.php',
    'src/Contracts/TracePropagatorInterface.php',
    'src/Managers/ResilientConnectionManager.php',
    'src/Managers/ConnectionPool.php',
    'test/Unit/QueueProfileTest.php',
    'test/Unit/ExchangeTopologyTest.php',
    'test/Unit/CorrelationContextTest.php',
    'test/Unit/W3cTracePropagatorTest.php',
    'test/Unit/ConsumerLifecycleTest.php',
    'test/Unit/ResilientConnectionManagerTest.php',
    'test/Unit/AmqpProductionFeaturesTest.php',
    // SAGA, events, middleware, testing, async publishing (v3.6)
    'src/Support/Saga.php',
    'src/Support/SagaResult.php',
    'src/Support/ConsumePipeline.php',
    'src/Support/EventDispatcher.php',
    'src/Support/AsyncPublisher.php',
    'src/Contracts/ConsumeMiddlewareInterface.php',
    'src/Events/MessagePublishing.php',
    'src/Events/MessagePublished.php',
    'src/Events/MessageReceived.php',
    'src/Events/MessageHandled.php',
    'src/Events/MessageFailed.php',
    'src/Testing/FakeAmqp.php',
    'src/Testing/NullPublisher.php',
    'src/Testing/NullPublisherFactory.php',
    'src/Testing/NullConsumer.php',
    'src/Testing/NullConsumerFactory.php',
    'test/Unit/SagaTest.php',
    'test/Unit/ConsumePipelineTest.php',
    'test/Unit/EventDispatcherTest.php',
    'test/Unit/FakeAmqpTest.php',
    'test/Unit/AsyncPublisherTest.php',
    'test/Unit/AmqpEventsAndPipelineTest.php',
];

$factory = new ParserFactory();

if (method_exists($factory, 'createForVersion')) {
    $parser = $factory->createForVersion(PhpVersion::fromString('7.3'));
} else {
    $parser = $factory->create(ParserFactory::PREFER_PHP7);
}

$root = dirname(__DIR__);
$failed = false;
foreach ($files as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . $relative;
    if (!is_file($path)) {
        echo "MISSING $relative\n";
        $failed = true;
        continue;
    }

    $code = file_get_contents($path);
    try {
        $parser->parse($code);
        echo "OK      $relative\n";
    } catch (Error $e) {
        echo "INVALID $relative: " . $e->getMessage() . "\n";
        $failed = true;
    }
}

exit($failed ? 1 : 0);
