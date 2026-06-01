<?php

/**
 * Verify every file under src/ parses as PHP 7.3 syntax.
 *
 * Usage: php scripts/check-php73-compat-all-src.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

$factory = new ParserFactory();
if (method_exists($factory, 'createForVersion')) {
    $parser = $factory->createForVersion(PhpVersion::fromString('7.3'));
} else {
    $parser = $factory->create(ParserFactory::PREFER_PHP7);
}

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src';
$failed = [];
$checked = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
        continue;
    }
    $checked++;
    $relative = 'src' . str_replace($root, '', $file->getPathname());
    $relative = str_replace('\\', '/', $relative);
    try {
        $parser->parse(file_get_contents($file->getPathname()));
    } catch (Error $e) {
        $failed[] = $relative . ': ' . $e->getMessage();
    }
}

if (!empty($failed)) {
    echo "PHP 7.3 parse failures:\n";
    echo implode("\n", $failed) . "\n";
    exit(1);
}

echo "OK: {$checked} file(s) under src/ parse as PHP 7.3\n";
exit(0);
