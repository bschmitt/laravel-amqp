<?php

namespace Bschmitt\Amqp\Exception;

use RuntimeException;

/**
 * Thrown by the typed-messaging helpers when a payload fails JSON-Schema
 * validation against the contract's `schema()` definition.
 *
 * `errors()` exposes the list of validator-produced error strings (one per
 * violation, including JSON pointer paths) so callers can log them or
 * surface them to operators.
 */
class SchemaValidationException extends RuntimeException
{
    /**
     * @var string[]
     */
    private $errors;

    /**
     * @param string[]        $errors  One human-readable string per violation.
     * @param string|null     $message Override for the default summary message.
     * @param \Throwable|null $previous
     */
    public function __construct(array $errors, ?string $message = null, ?\Throwable $previous = null)
    {
        $summary = $message ?? sprintf('Schema validation failed with %d error(s): %s',
            count($errors),
            implode('; ', array_slice($errors, 0, 3))
        );
        parent::__construct($summary, 0, $previous);
        $this->errors = array_values($errors);
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
