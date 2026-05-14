<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator\Inspectors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final class FormRequestInspector
{
    public function apply(array &$record, Route $route): void
    {
        $formRequestClass = $this->findFormRequest($route);
        if ($formRequestClass === null) {
            return;
        }
        try {
            $instance = app($formRequestClass);
            if (! method_exists($instance, 'rules')) {
                return;
            }
            $rules = $instance->rules();
            if (! is_array($rules)) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $params = [];
        foreach ($rules as $field => $rule) {
            $ruleList = is_array($rule)
                ? array_map(static fn ($r) => is_object($r) ? $r::class : (string) $r, $rule)
                : array_filter(array_map('trim', explode('|', (string) $rule)));

            $type     = $this->inferType($ruleList);
            $required = in_array('required', $ruleList, true);

            $params[] = [
                'name'        => (string) $field,
                'in'          => in_array($record['method'], ['GET', 'DELETE', 'HEAD'], true) ? 'query' : 'body',
                'required'    => $required,
                'type'        => $type,
                'description' => $this->summarizeRules($ruleList),
                'example'     => null,
            ];
        }

        // Merge — Form Request takes precedence for body/query if overlay hasn't run yet
        if ($params !== []) {
            $existing = collect($record['parameters'])->keyBy(fn ($p) => $p['in'] . ':' . $p['name']);
            foreach ($params as $p) {
                $existing[$p['in'] . ':' . $p['name']] = $p;
            }
            $record['parameters'] = array_values($existing->toArray());
        }
    }

    private function findFormRequest(Route $route): ?string
    {
        $action = $route->getActionName();
        if (! is_string($action) || ! str_contains($action, '@')) {
            return null;
        }
        [$class, $method] = explode('@', $action, 2);
        try {
            if (! class_exists($class)) {
                return null;
            }
            $r = (new ReflectionClass($class))->getMethod($method);
        } catch (Throwable) {
            return null;
        }

        foreach ($r->getParameters() as $p) {
            $t = $p->getType();
            if (! $t instanceof ReflectionNamedType) {
                continue;
            }
            $name = $t->getName();
            if (class_exists($name) && is_subclass_of($name, FormRequest::class)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $rules
     */
    private function inferType(array $rules): string
    {
        foreach (['integer', 'numeric', 'boolean', 'array', 'string', 'email', 'url', 'uuid', 'date', 'file'] as $hint) {
            if (in_array($hint, $rules, true)) {
                return match ($hint) {
                    'integer', 'numeric' => 'number',
                    'boolean'           => 'boolean',
                    'array'             => 'array',
                    default             => 'string',
                };
            }
        }

        return 'string';
    }

    /**
     * @param array<int, string> $rules
     */
    private function summarizeRules(array $rules): string
    {
        return implode(', ', array_filter($rules, static fn ($r) => $r !== ''));
    }
}
