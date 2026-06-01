<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Exception\SchemaValidationException;
use Bschmitt\Amqp\Support\SchemaValidator;
use Bschmitt\Amqp\Test\Support\BaseTestCase;

/**
 * Behavioural coverage for {@see SchemaValidator}.
 *
 * The validator is intentionally a JSON-Schema **subset**, so tests focus on
 * the keywords actually documented as supported. Each block targets one
 * keyword family (type, string, number, array, object, composition) and
 * asserts both happy and error paths so future regressions surface quickly.
 */
class SchemaValidatorTest extends BaseTestCase
{
    /** @var SchemaValidator */
    private $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SchemaValidator();
    }

    /* -------------------- type system -------------------- */

    public function testTypeAcceptsAllowedJsonTypes(): void
    {
        $cases = [
            'string'  => ['hello', ['type' => 'string']],
            'integer' => [42, ['type' => 'integer']],
            'number'  => [3.14, ['type' => 'number']],
            'boolean' => [true, ['type' => 'boolean']],
            'null'    => [null, ['type' => 'null']],
            'object'  => [['a' => 1], ['type' => 'object']],
            'list'    => [[1, 2], ['type' => 'array']],
            'empty-list' => [[], ['type' => 'array']],
        ];

        foreach ($cases as $label => [$data, $schema]) {
            $this->assertSame([], $this->validator->validate($data, $schema),
                "Case [$label] should validate");
        }
    }

    public function testTypeRejectsObjectAgainstArraySchema(): void
    {
        $errors = $this->validator->validate(['a' => 1], ['type' => 'array']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('expected type array', $errors[0]);
    }

    public function testTypeAcceptsArrayOfAllowedTypes(): void
    {
        $this->assertSame([], $this->validator->validate('x', ['type' => ['string', 'null']]));
        $this->assertSame([], $this->validator->validate(null, ['type' => ['string', 'null']]));
        $this->assertNotEmpty($this->validator->validate(1, ['type' => ['string', 'null']]));
    }

    public function testIntegerTypeAcceptsFloatThatIsWhole(): void
    {
        $this->assertSame([], $this->validator->validate(5.0, ['type' => 'integer']));
        $this->assertNotEmpty($this->validator->validate(5.5, ['type' => 'integer']));
    }

    /* -------------------- string -------------------- */

    public function testStringLengthBounds(): void
    {
        $schema = ['type' => 'string', 'minLength' => 2, 'maxLength' => 4];
        $this->assertSame([], $this->validator->validate('ab', $schema));
        $this->assertSame([], $this->validator->validate('abcd', $schema));
        $this->assertNotEmpty($this->validator->validate('a', $schema));
        $this->assertNotEmpty($this->validator->validate('abcde', $schema));
    }

    public function testStringPattern(): void
    {
        $schema = ['type' => 'string', 'pattern' => '^[A-Z]{3}$'];
        $this->assertSame([], $this->validator->validate('USD', $schema));
        $this->assertNotEmpty($this->validator->validate('usd', $schema));
        $this->assertNotEmpty($this->validator->validate('USDX', $schema));
    }

    public function testStringFormatEmail(): void
    {
        $schema = ['type' => 'string', 'format' => 'email'];
        $this->assertSame([], $this->validator->validate('test@example.com', $schema));
        $this->assertNotEmpty($this->validator->validate('not-an-email', $schema));
    }

    public function testStringFormatUuid(): void
    {
        $schema = ['type' => 'string', 'format' => 'uuid'];
        $this->assertSame([], $this->validator->validate('a1b2c3d4-e5f6-1234-9876-abcdef012345', $schema));
        $this->assertNotEmpty($this->validator->validate('not-a-uuid', $schema));
    }

    public function testStringFormatDateAndDateTime(): void
    {
        $this->assertSame([], $this->validator->validate('2024-06-01', ['type' => 'string', 'format' => 'date']));
        $this->assertNotEmpty($this->validator->validate('06-01-2024', ['type' => 'string', 'format' => 'date']));

        $this->assertSame([], $this->validator->validate('2024-06-01T12:00:00Z', ['type' => 'string', 'format' => 'date-time']));
        $this->assertNotEmpty($this->validator->validate('not a timestamp', ['type' => 'string', 'format' => 'date-time']));
    }

    public function testUnknownFormatIsAdvisoryNotError(): void
    {
        $this->assertSame([], $this->validator->validate('anything', ['type' => 'string', 'format' => 'made-up']));
    }

    /* -------------------- number -------------------- */

    public function testMinimumAndMaximum(): void
    {
        $schema = ['type' => 'number', 'minimum' => 0, 'maximum' => 100];
        $this->assertSame([], $this->validator->validate(0, $schema));
        $this->assertSame([], $this->validator->validate(100, $schema));
        $this->assertSame([], $this->validator->validate(50.5, $schema));
        $this->assertNotEmpty($this->validator->validate(-1, $schema));
        $this->assertNotEmpty($this->validator->validate(101, $schema));
    }

    public function testExclusiveBounds(): void
    {
        $schema = ['type' => 'number', 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 10];
        $this->assertSame([], $this->validator->validate(1, $schema));
        $this->assertNotEmpty($this->validator->validate(0, $schema));
        $this->assertNotEmpty($this->validator->validate(10, $schema));
    }

    public function testMultipleOf(): void
    {
        $schema = ['type' => 'number', 'multipleOf' => 0.5];
        $this->assertSame([], $this->validator->validate(1.5, $schema));
        $this->assertSame([], $this->validator->validate(2.0, $schema));
        $this->assertNotEmpty($this->validator->validate(1.3, $schema));
    }

    /* -------------------- array -------------------- */

    public function testArrayLengthBounds(): void
    {
        $schema = ['type' => 'array', 'minItems' => 1, 'maxItems' => 3];
        $this->assertSame([], $this->validator->validate(['a'], $schema));
        $this->assertSame([], $this->validator->validate(['a', 'b', 'c'], $schema));
        $this->assertNotEmpty($this->validator->validate([], $schema));
        $this->assertNotEmpty($this->validator->validate(['a', 'b', 'c', 'd'], $schema));
    }

    public function testArrayItemsAreValidatedRecursively(): void
    {
        $schema = [
            'type'  => 'array',
            'items' => ['type' => 'integer', 'minimum' => 0],
        ];
        $this->assertSame([], $this->validator->validate([1, 2, 3], $schema));

        $errors = $this->validator->validate([1, -2, 3], $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('/1', $errors[0], 'error path must point at offending index');
    }

    public function testUniqueItems(): void
    {
        $schema = ['type' => 'array', 'uniqueItems' => true];
        $this->assertSame([], $this->validator->validate(['a', 'b', 'c'], $schema));
        $this->assertNotEmpty($this->validator->validate(['a', 'b', 'a'], $schema));
        $this->assertNotEmpty($this->validator->validate([['x' => 1], ['x' => 1]], $schema));
    }

    /* -------------------- object -------------------- */

    public function testRequiredProperties(): void
    {
        $schema = ['type' => 'object', 'required' => ['orderId', 'total']];
        $this->assertSame([], $this->validator->validate(['orderId' => '1', 'total' => 9.99], $schema));

        $errors = $this->validator->validate(['orderId' => '1'], $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('total', $errors[0]);
    }

    public function testNestedPropertyValidation(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'order' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'string', 'minLength' => 1],
                    ],
                ],
            ],
        ];

        $this->assertSame([], $this->validator->validate(['order' => ['id' => 'x']], $schema));

        $errors = $this->validator->validate(['order' => ['id' => '']], $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('/order/id', $errors[0]);
    }

    public function testAdditionalPropertiesFalseRejectsExtras(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
        $this->assertSame([], $this->validator->validate(['name' => 'x'], $schema));

        $errors = $this->validator->validate(['name' => 'x', 'extra' => 'y'], $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('extra', $errors[0]);
    }

    public function testAdditionalPropertiesSchemaIsAppliedToExtras(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => ['type' => 'integer'],
        ];
        $this->assertSame([], $this->validator->validate(['name' => 'x', 'count' => 4], $schema));
        $this->assertNotEmpty($this->validator->validate(['name' => 'x', 'count' => 'four'], $schema));
    }

    public function testMinAndMaxProperties(): void
    {
        $schema = ['type' => 'object', 'minProperties' => 1, 'maxProperties' => 2];
        $this->assertSame([], $this->validator->validate(['a' => 1], $schema));
        $this->assertNotEmpty($this->validator->validate([], $schema));
        $this->assertNotEmpty($this->validator->validate(['a' => 1, 'b' => 2, 'c' => 3], $schema));
    }

    /* -------------------- enum / const -------------------- */

    public function testEnum(): void
    {
        $schema = ['enum' => ['USD', 'EUR', 'GBP']];
        $this->assertSame([], $this->validator->validate('USD', $schema));
        $this->assertNotEmpty($this->validator->validate('JPY', $schema));
    }

    public function testConst(): void
    {
        $schema = ['const' => ['kind' => 'event']];
        $this->assertSame([], $this->validator->validate(['kind' => 'event'], $schema));
        $this->assertNotEmpty($this->validator->validate(['kind' => 'command'], $schema));
    }

    /* -------------------- composition -------------------- */

    public function testAllOfRequiresEverySubschema(): void
    {
        $schema = [
            'allOf' => [
                ['type' => 'string'],
                ['minLength' => 3],
            ],
        ];
        $this->assertSame([], $this->validator->validate('abc', $schema));
        $this->assertNotEmpty($this->validator->validate('ab', $schema));
        $this->assertNotEmpty($this->validator->validate(123, $schema));
    }

    public function testAnyOfRequiresAtLeastOne(): void
    {
        $schema = [
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];
        $this->assertSame([], $this->validator->validate('x', $schema));
        $this->assertSame([], $this->validator->validate(1, $schema));
        $this->assertNotEmpty($this->validator->validate(1.5, $schema));
    }

    public function testOneOfRequiresExactlyOne(): void
    {
        $schema = [
            'oneOf' => [
                ['type' => 'integer'],
                ['type' => 'string'],
            ],
        ];
        $this->assertSame([], $this->validator->validate(1, $schema));
        // matches both (integer + number) — should fail oneOf
        $schemaConflicting = [
            'oneOf' => [
                ['type' => 'integer'],
                ['type' => 'number'],
            ],
        ];
        $this->assertNotEmpty($this->validator->validate(1, $schemaConflicting));
    }

    public function testNot(): void
    {
        $schema = ['not' => ['type' => 'string']];
        $this->assertSame([], $this->validator->validate(1, $schema));
        $this->assertNotEmpty($this->validator->validate('x', $schema));
    }

    /* -------------------- assertValid + path reporting -------------------- */

    public function testAssertValidThrowsSchemaValidationException(): void
    {
        $this->expectException(SchemaValidationException::class);
        $this->validator->assertValid(['orderId' => 1], [
            'type' => 'object',
            'properties' => ['orderId' => ['type' => 'string']],
        ]);
    }

    public function testAssertValidExceptionCarriesErrorList(): void
    {
        try {
            $this->validator->assertValid(['orderId' => 1, 'extra' => true], [
                'type' => 'object',
                'properties' => ['orderId' => ['type' => 'string']],
                'additionalProperties' => false,
                'required' => ['total'],
            ]);
            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $errors = $e->errors();
            $this->assertGreaterThanOrEqual(2, count($errors));
            $this->assertIsString($errors[0]);
        }
    }

    public function testRootPathReportedAsRoot(): void
    {
        $errors = $this->validator->validate('x', ['type' => 'integer']);
        $this->assertStringStartsWith('(root):', $errors[0]);
    }
}
