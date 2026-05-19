<?php

namespace Bschmitt\Amqp\Test\Support;

/**
 * PHP 7.3/7.4 requires setAccessible(true) before reading/writing non-public members via reflection.
 */
trait ReflectionTestTrait
{
    protected function reflectionAccessible($ref): void
    {
        if ($ref instanceof \ReflectionProperty || $ref instanceof \ReflectionMethod) {
            $ref->setAccessible(true);
        }
    }

    protected function setProtectedProperty($class, $object, string $propertyName, $value): void
    {
        $property = (new \ReflectionClass($class))->getProperty($propertyName);
        $this->reflectionAccessible($property);
        $property->setValue($object, $value);
    }

    protected function getProtectedProperty($object, string $propertyName)
    {
        $property = (new \ReflectionObject($object))->getProperty($propertyName);
        $this->reflectionAccessible($property);

        return $property->getValue($object);
    }

    protected function invokeProtectedMethod($object, string $methodName, array $args = [])
    {
        $method = (new \ReflectionObject($object))->getMethod($methodName);
        $this->reflectionAccessible($method);

        return $method->invokeArgs($object, $args);
    }
}
