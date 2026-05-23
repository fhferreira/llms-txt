<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Tests\Unit;

use Fhferreira\LlmsTxt\Generator\RulesToJsonSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RulesToJsonSchemaTest extends TestCase
{
    #[Test]
    public function required_string_with_max_emits_string_with_max_length(): void
    {
        $out = RulesToJsonSchema::convert(['required', 'string', 'max:255']);

        self::assertSame('string', $out['schema']['type']);
        self::assertSame(255, $out['schema']['maxLength']);
        self::assertTrue($out['required']);
        self::assertFalse($out['nullable']);
    }

    #[Test]
    public function integer_with_min_and_max_uses_numeric_bounds(): void
    {
        $out = RulesToJsonSchema::convert(['integer', 'min:1', 'max:250']);

        self::assertSame('integer', $out['schema']['type']);
        self::assertSame(1, $out['schema']['minimum']);
        self::assertSame(250, $out['schema']['maximum']);
    }

    #[Test]
    public function numeric_becomes_number_type(): void
    {
        $out = RulesToJsonSchema::convert(['numeric', 'min:0.5']);

        self::assertSame('number', $out['schema']['type']);
        self::assertSame(0.5, $out['schema']['minimum']);
    }

    #[Test]
    public function in_rule_becomes_enum(): void
    {
        $out = RulesToJsonSchema::convert(['string', 'in:paid,pending,refunded']);

        self::assertSame(['paid', 'pending', 'refunded'], $out['schema']['enum']);
    }

    #[Test]
    public function email_url_uuid_get_format_keyword(): void
    {
        self::assertSame('email', RulesToJsonSchema::convert(['email'])['schema']['format']);
        self::assertSame('uri',   RulesToJsonSchema::convert(['url'])['schema']['format']);
        self::assertSame('uuid',  RulesToJsonSchema::convert(['uuid'])['schema']['format']);
    }

    #[Test]
    public function nullable_unions_with_null_type(): void
    {
        $out = RulesToJsonSchema::convert(['nullable', 'string']);

        self::assertSame(['string', 'null'], $out['schema']['type']);
        self::assertTrue($out['nullable']);
    }

    #[Test]
    public function boolean_rule_emits_boolean_type(): void
    {
        $out = RulesToJsonSchema::convert(['boolean']);

        self::assertSame('boolean', $out['schema']['type']);
    }

    #[Test]
    public function array_rule_emits_array_type_with_size_bounds(): void
    {
        $out = RulesToJsonSchema::convert(['array', 'min:1', 'max:50']);

        self::assertSame('array', $out['schema']['type']);
        self::assertSame(1, $out['schema']['minItems']);
        self::assertSame(50, $out['schema']['maxItems']);
    }

    #[Test]
    public function defaults_to_string_when_no_type_rule_present(): void
    {
        $out = RulesToJsonSchema::convert(['required']);

        self::assertSame('string', $out['schema']['type']);
        self::assertTrue($out['required']);
    }
}