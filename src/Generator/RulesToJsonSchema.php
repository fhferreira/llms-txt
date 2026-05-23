<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator;

/**
 * Map a Laravel validation rule list onto a JSON Schema property fragment.
 *
 *   ['required', 'string', 'max:255']  →  ['type' => 'string', 'maxLength' => 255]
 *   ['integer', 'min:1', 'max:250']    →  ['type' => 'integer', 'minimum' => 1, 'maximum' => 250]
 *   ['in:paid,pending']                →  ['type' => 'string', 'enum' => ['paid','pending']]
 */
final class RulesToJsonSchema
{
    /**
     * @param  array<int, string>  $rules
     * @return array{schema: array<string, mixed>, required: bool, nullable: bool}
     */
    public static function convert(array $rules): array
    {
        $rules = array_values(array_filter(array_map(static fn ($r) => is_string($r) ? trim($r) : $r, $rules), static fn ($r) => $r !== ''));

        $required = in_array('required', $rules, true);
        $nullable = in_array('nullable', $rules, true);
        $type     = self::inferType($rules);

        $schema = $nullable
            ? ['type' => [$type, 'null']]
            : ['type' => $type];

        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }
            [$head, $arg] = self::split($rule);
            switch ($head) {
                case 'email':
                    $schema['format'] = 'email';
                    break;
                case 'url':
                    $schema['format'] = 'uri';
                    break;
                case 'uuid':
                    $schema['format'] = 'uuid';
                    break;
                case 'date':
                    $schema['format'] = 'date-time';
                    break;
                case 'in':
                    $schema['enum'] = array_map('trim', explode(',', $arg));
                    break;
                case 'min':
                    if ($type === 'string') {
                        $schema['minLength'] = (int) $arg;
                    } elseif ($type === 'integer' || $type === 'number') {
                        $schema['minimum'] = self::numeric($arg);
                    } elseif ($type === 'array') {
                        $schema['minItems'] = (int) $arg;
                    }
                    break;
                case 'max':
                    if ($type === 'string') {
                        $schema['maxLength'] = (int) $arg;
                    } elseif ($type === 'integer' || $type === 'number') {
                        $schema['maximum'] = self::numeric($arg);
                    } elseif ($type === 'array') {
                        $schema['maxItems'] = (int) $arg;
                    }
                    break;
                case 'size':
                    if ($type === 'string') {
                        $schema['minLength'] = (int) $arg;
                        $schema['maxLength'] = (int) $arg;
                    } elseif ($type === 'integer' || $type === 'number') {
                        $schema['minimum'] = self::numeric($arg);
                        $schema['maximum'] = self::numeric($arg);
                    }
                    break;
            }
        }

        return [
            'schema'   => $schema,
            'required' => $required,
            'nullable' => $nullable,
        ];
    }

    /**
     * @param  array<int, string>  $rules
     */
    private static function inferType(array $rules): string
    {
        foreach (['integer', 'numeric', 'boolean', 'array'] as $hint) {
            if (in_array($hint, $rules, true)) {
                return match ($hint) {
                    'integer' => 'integer',
                    'numeric' => 'number',
                    'boolean' => 'boolean',
                    'array'   => 'array',
                };
            }
        }

        return 'string';
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function split(string $rule): array
    {
        if (! str_contains($rule, ':')) {
            return [$rule, ''];
        }
        [$head, $arg] = explode(':', $rule, 2);

        return [$head, $arg];
    }

    private static function numeric(string $arg): int|float
    {
        return str_contains($arg, '.') ? (float) $arg : (int) $arg;
    }
}