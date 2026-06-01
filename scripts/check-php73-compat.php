<?php

/**
 * Static check that the new retry/DLQ files parse as valid PHP 7.3 syntax.
 *
 * Uses nikic/php-parser (already a transitive dev dependency) to fail fast on
 * 7.4+ syntax such as numeric literal separators, arrow functions, typed
 * properties, or named arguments.
 *
 * Usage: php scripts/check-php73-compat.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

$files = [
    'src/Support/RetryPolicy.php',
    'src/Support/DeadLetterTopology.php',
    'src/Support/RetryHandler.php',
    'src/Console/Commands/AmqpWorkCommand.php',
    'src/Core/Amqp.php',
    'test/Unit/RetryPolicyTest.php',
    'test/Unit/DeadLetterTopologyTest.php',
    'test/Unit/RetryHandlerTest.php',
    'test/Unit/AmqpRetryTopologyTest.php',
    'test/Unit/Console/AmqpWorkCommandTest.php',
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
