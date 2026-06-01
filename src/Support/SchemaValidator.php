<?php

namespace Bschmitt\Amqp\Support;

/**
 * Zero-dependency JSON Schema validator covering the keywords actually used
 * by message contracts.
 *
 * Intentionally a **subset** of JSON Schema Draft 7 — enough to declare
 * realistic message shapes without dragging `justinrainbow/json-schema` (and
 * its constraint factories) into the package's dependency graph.
 *
 * Supported keywords:
 *
 *  Type system   : `type` (string or array of strings; `string`, `integer`,
 *                  `number`, `boolean`, `null`, `array`, `object`)
 *  Object        : `required`, `properties`, `additionalProperties`,
 *                  `minProperties`, `maxProperties`
 *  Array         : `items` (single schema), `minItems`, `maxItems`,
 *                  `uniqueItems`
 *  String        : `minLength`, `maxLength`, `pattern`, `format`
 *                  (email, uri, uuid, date-time, date)
 *  Number        : `minimum`, `maximum`, `exclusiveMinimum`,
 *                  `exclusiveMaximum`, `multipleOf`
 *  Any           : `enum`, `const`
 *  Composition   : `oneOf`, `anyOf`, `allOf`, `not`
 *
 * Not supported (intentionally): `$ref`, `$id`, `if/then/else`, `patternProperties`,
 * tuple `items` arrays, `contains`, `dependencies`, advanced `format`s.
 *
 * `validate()` returns a list of error strings (empty = valid). Each error
 * includes a JSON-pointer-ish path like `/orders/0/total` so they line up
 * with the offending field even in deeply-nested payloads.
 */
class SchemaValidator
{
    /**
     * Validate `$data` against `$schema`.
     *
     * @param mixed                $data
     * @param array<string, mixed> $schema
     * @param string               $path Internal cursor (callers pass '').
     *
     * @return string[] Error messages (empty array = valid).
     */
    public function validate($data, array $schema, string $path = ''): array
    {
        $errors = [];

        $this->validateConst($data, $schema, $path, $errors);
        $this->validateEnum($data, $schema, $path, $errors);
        $this->validateType($data, $schema, $path, $errors);
        $this->validateString($data, $schema, $path, $errors);
        $this->validateNumber($data, $schema, $path, $errors);
        $this->validateArray($data, $schema, $path, $errors);
        $this->validateObject($data, $schema, $path, $errors);
        $this->validateCompositions($data, $schema, $path, $errors);

        return $errors;
    }

    /**
     * Sugar wrapper: validate and throw on failure.
     *
     * @param mixed                $data
     * @param array<string, mixed> $schema
     * @throws \Bschmitt\Amqp\Exception\SchemaValidationException
     */
    public function assertValid($data, array $schema): void
    {
        $errors = $this->validate($data, $schema);
        if (!empty($errors)) {
            throw new \Bschmitt\Amqp\Exception\SchemaValidationException($errors);
        }
    }

    /* -------------------- type system -------------------- */

    /**
     * @param mixed $data
     * @param array<string, mixed> $schema
     * @param string $path
     * @param string[] $errors
     */
    protected function validateType($data, array $schema, string $path, array &$errors): void
    {
        if (!array_key_exists('type', $schema)) {
            return;
        }

        $allowed = (array) $schema['type'];
        foreach ($allowed as $type) {
            if ($this->matchesType($data, (string) $type)) {
                return;
            }
        }

        $errors[] = $this->describe($path, sprintf('expected type %s, got %s',
            implode('|', $allowed), $this->typeOf($data)));
    }

    /**
     * @param mixed $data
     */
    protected function matchesType($data, string $type): bool
    {
        switch ($type) {
            case 'string':  return is_string($data);
            case 'integer': return is_int($data) || (is_float($data) && floor($data) === $data && !is_nan($data));
            case 'number':  return is_int($data) || is_float($data);
            case 'boolean': return is_bool($data);
            case 'null':    return $data === null;
            case 'array':   return is_array($data) && $this->isList($data);
            case 'object':  return is_array($data) && !$this->isList($data);
            default:        return false;
        }
    }

    /**
     * @param mixed $data
     */
    protected function typeOf($data): string
    {
        if (is_string($data))  return 'string';
        if (is_int($data))     return 'integer';
        if (is_float($data))   return 'number';
        if (is_bool($data))    return 'boolean';
        if ($data === null)    return 'null';
        if (is_array($data))   return $this->isList($data) ? 'array' : 'object';
        return gettype($data);
    }

    /**
     * @param array<mixed> $arr
     */
    protected function isList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }

    /* -------------------- string -------------------- */

    /**
     * @param mixed $data
     * @param array<string, mixed> $schema
     * @param string $path
     * @param string[] $errors
     */
    protected function validateString($data, array $schema, string $path, array &$errors): void
    {
        if (!is_string($data)) {
            return;
        }

        if (isset($schema['minLength']) && $this->utf8len($data) < (int) $schema['minLength']) {
            $errors[] = $this->describe($path, sprintf('string length %d is less than minLength %d',
                $this->utf8len($data), $schema['minLength']));
        }
        if (isset($schema['maxLength']) && $this->utf8len($data) > (int) $schema['maxLength']) {
            $errors[] = $this->describe($path, sprintf('string length %d exceeds maxLength %d',
                $this->utf8len($data), $schema['maxLength']));
        }

        if (isset($schema['pattern'])) {
            $pattern = '#'.str_replace('#', '\\#', (string) $schema['pattern']).'#';
            if (@preg_match($pattern, $data) !== 1) {
                $errors[] = $this->describe($path, sprintf('string does not match pattern %s',
                    $schema['pattern']));
            }
        }

        if (isset($schema['format'])) {
            $format = (string) $schema['format'];
            if (!$this->matchesFormat($data, $format)) {
                $errors[] = $this->describe($path, sprintf('string is not a valid %s', $format));
            }
        }
    }

    protected function utf8len(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s, '8bit') === strlen($s)
                ? mb_strlen($s, 'UTF-8')
                : mb_strlen($s);
        }
        return strlen($s);
    }

    protected function matchesFormat(string $value, string $format): bool
    {
        switch ($format) {
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            case 'uri':
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false;
            case 'uuid':
                return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
            case 'date':
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                    && $this->isParseableDate($value);
            case 'date-time':
                return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+\-]\d{2}:?\d{2})?$/', $value) === 1
                    && $this->isParseableDate($value);
            case 'ipv4':
                return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            case 'ipv6':
                return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        // Unknown formats are treated as advisory (per JSON Schema spec).
        return true;
    }

    protected function isParseableDate(string $value): bool
    {
        try {
            new \DateTimeImmutable($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /* -------------------- number / integer -------------------- */

    /**
     * @param mixed $data
     * @param array<string, mixed> $schema
     * @param string $path
     * @param string[] $errors
     */
    protected function validateNumber($data, array $schema, string $path, array &$errors): void
    {
        if (!is_int($data) && !is_float($data)) {
            return;
        }
        if (is_bool($data)) {
            return; // bools are technically int in PHP but not in JSON Schema
        }

        if (array_key_exists('minimum', $schema) && $data < $schema['minimum']) {
            $errors[] = $this->describe($path, sprintf('value %s is less than minimum %s', $data, $schema['minimum']));
        }
        if (array_key_exists('maximum', $schema) && $data > $schema['maximum']) {
            $errors[] = $this->describe($path, sprintf('value %s exceeds maximum %s', $data, $schema['maximum']));
        }
        if (array_key_exists('exclusiveMinimum', $schema) && $data <= $schema['exclusiveMinimum']) {
            $errors[] = $this->describe($path, sprintf('value %s must be > %s', $data, $schema['exclusiveMinimum']));
        }
        if (array_key_exists('exclusiveMaximum', $schema) && $data >= $schema['exclusiveMaximum']) {
            $errors[] = $this->describe($path, sprintf('value %s must be < %s', $data, $schema['exclusiveMaximum']));
        }
        if (array_key_exists('multipleOf', $schema) && $schema['multipleOf'] != 0) {
            $div = $data / $schema['multipleOf'];
            if (abs($div - round($div)) > 1e-9) {
                $errors[] = $this->describe($path, sprintf('value %s is not a multiple of %s', $data, $schema['multipleOf']));
            }
        }
    }

    /* -------------------- array -------------------- */

    /**
     * @param mixed $data
     * @param array<string, mixed> $schema
     * @param string $path
     * @param string[] $errors
     */
    protected function validateArray($data, array $schema, string $path, array &$errors): void
    {
        if (!is_array($data) || !$this->isList($data)) {
            return;
        }

        if (isset($schema['minItems']) && count($data) < (int) $schema['minItems']) {
            $errors[] = $this->describe($path, sprintf('array length %d is less than minItems %d',
                count($data), $schema['minItems']));
        }
        if (isset($schema['maxItems']) && count($data) > (int) $schema['maxItems']) {
            $errors[] = $this->describe($path, sprintf('array length %d exceeds maxItems %d',
                count($data), $schema['maxItems']));
        }

        if (!empty($schema['uniqueItems'])) {
            $seen = [];
            foreach ($data as $idx => $item) {
                $key = is_scalar($item) ? (string) $item : md5(serialize($item));
                if (isset($seen[$key])) {
                    $errors[] = $this->describe($path.'/'.$idx, 'duplicate item violates uniqueItems');
                    break;
                }
                $seen[$key] = true;
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($data as $idx => $item) {
                foreach ($this->validate($item, $schema['items'], $path.'/'.$idx) as $childError) {
                    $errors[] = $childError;
                }
            }
        }
    }

    /* -------------------- object -------------------- */

    /**
     * @param mixed $data
     * @param array<string, mixed> $schema
     * @param string $path
     * @param string[] $errors
     */
    protected function validateObject($data, array $schema, string $path, array &$errors): void
    {
        if (!is_array($data) || $this->isList($data)) {
            return;
        }

        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $key) {
                if (!array_key_exists($key, $data)) {
                    $errors[] = $this->describe($path.'/'.$key, 'required property is missing');
                }
            }
        }

        if (isset($schema['minProperties']) && count($data) < (int) $schema['minProperties']) {
            $errors[] = $this->describe($path, sprintf('object has %d properties, minProperties is %d',
                count($data), $schema['minProperties']));
        }
        if (isset($schema['maxProperties']) && count($data) > (int) $schema['maxProperties']) {
            $errors[] = $this->describe($path, sprintf('object has %d properties, maxProperties is %d',
                count($data), $schema['maxProperties']));
        }

        $properties = (isset($schema['properties']) && is_array($schema['properties']))
            ? $schema['properties']
            : [];

        foreach ($properties as $name => $propertySchema) {
            if (array_key_exists($name, $data) && is_array($propertySchema)) {
                foreach ($this->validate($data[$name], $propertySchema, $path.'/'.$name) as $childError) {
                    $errors[] = $childError;
                }
            }
        }

        if (array_key_exists('additionalProperties', $schema)) {
            $additional = $schema['additionalProperties'];
            $allowedKeys = array_keys($properties);

            if ($additional === false) {
                foreach ($data as $key => $_) {
                    if (!in_array($key, $allowedKeys, true)) {
                        $errors[] = $this->describe($path.'/'.$key, 'additional properties are not allowed');
                    }
                }
            } elseif (is_array($additional)) {
                foreach ($data as $key => $value) {
                    if (!in_array($key, $allowedKeys, true)) {
                        foreach ($this->validate($value, $additional, $path.'/'.$key) as $childError) {
                            $errors[] = $childError;
                        }
                    }
                }
            }
        }
    }

    /* -------------------- enum / const -------------------- */

    /**
     * @param mixed $data
     * @param array<string, mixed> $schema
     * @param string $path
     * @param string[] $errors
     */
    protected function validateEnum($data, array $schema, string $path, array &$errors): void
    {
        if (!array_key_exists('enum', $schema)) {
            return;
        }
        foreach ((array) $schema['enum'] as $allowed) {
            if ($this->deepEqual($allowed, $data)) {
                return;
            }
        }
        $errors[] = $this->describe($path, 'value is not one of the allowed enum entries');
    }

    /**
     * @param mixed $data
     * @param array<string, mixed> $schema
     * @param string $path
     * @param string[] $errors
     */
    protected function validateConst($data, array $schema, string $path, array &$errors): void
    {
        if (!array_key_exists('const', $schema)) {
            return;
        }
        if (!$this->deepEqual($schema['const'], $data)) {
            $errors[] = $this->describe($path, 'value does not equal expected const');
        }
    }

    /**
     * @param mixed $a
     * @param mixed $b
     */
    protected function deepEqual($a, $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!$this->deepEqual($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }

    /* -------------------- compositions -------------------- */

    /**
     * @param mixed $data
     * @param array<string, mixed> $schema
     * @param string $path
     * @param string[] $errors
     */
    protected function validateCompositions($data, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $sub) {
                if (is_array($sub)) {
                    foreach ($this->validate($data, $sub, $path) as $childError) {
                        $errors[] = $childError;
                    }
                }
            }
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $matched = false;
            foreach ($schema['anyOf'] as $sub) {
                if (is_array($sub) && empty($this->validate($data, $sub, $path))) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $errors[] = $this->describe($path, 'value does not match any of anyOf schemas');
            }
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $matches = 0;
            foreach ($schema['oneOf'] as $sub) {
                if (is_array($sub) && empty($this->validate($data, $sub, $path))) {
                    $matches++;
                }
            }
            if ($matches !== 1) {
                $errors[] = $this->describe($path, sprintf('value matched %d oneOf schemas (expected exactly 1)', $matches));
            }
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            if (empty($this->validate($data, $schema['not'], $path))) {
                $errors[] = $this->describe($path, 'value matched "not" schema');
            }
        }
    }

    /* -------------------- helpers -------------------- */

    protected function describe(string $path, string $message): string
    {
        $location = $path === '' ? '(root)' : $path;
        return $location.': '.$message;
    }
}
