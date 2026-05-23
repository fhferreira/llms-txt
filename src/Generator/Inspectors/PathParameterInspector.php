<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator\Inspectors;

use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * Make every `{placeholder}` in the route URI an explicit, required path
 * parameter. Refines `type` using the controller method signature when present
 * (e.g. `int $orderId` → integer).
 */
final class PathParameterInspector
{
    public function apply(array &$record, Route $route): void
    {
        if (! preg_match_all('/\{([^}?]+)(\??)\}/', (string) $record['path'], $m)) {
            return;
        }
        $typeHints = $this->controllerParamTypes($route);

        $existing = collect($record['parameters'])->keyBy(fn ($p) => $p['in'] . ':' . $p['name']);

        foreach ($m[1] as $i => $rawName) {
            $name     = $rawName;
            $optional = $m[2][$i] === '?';
            $key      = 'path:' . $name;
            if ($existing->has($key)) {
                $p = $existing->get($key);
                if (! isset($p['type']) || $p['type'] === 'string') {
                    $p['type'] = $typeHints[$name] ?? $p['type'] ?? 'string';
                }
                $p['required'] = ! $optional;
                $existing->put($key, $p);

                continue;
            }
            $existing->put($key, [
                'name'        => $name,
                'in'          => 'path',
                'required'    => ! $optional,
                'type'        => $typeHints[$name] ?? 'string',
                'description' => '',
                'example'     => null,
            ]);
        }

        $record['parameters'] = array_values($existing->toArray());
    }

    /**
     * @return array<string, string>  parameter name → JSON-Schema-ish type
     */
    private function controllerParamTypes(Route $route): array
    {
        $action = $route->getActionName();
        if (! is_string($action) || ! str_contains($action, '@')) {
            return [];
        }
        [$class, $method] = explode('@', $action, 2);
        try {
            if (! class_exists($class)) {
                return [];
            }
            $r = new ReflectionClass($class);
            if (! $r->hasMethod($method)) {
                return [];
            }
            $out = [];
            foreach ($r->getMethod($method)->getParameters() as $p) {
                $t = $p->getType();
                if (! $t instanceof ReflectionNamedType || $t->isBuiltin() === false) {
                    continue;
                }
                $out[$p->getName()] = match ($t->getName()) {
                    'int'   => 'integer',
                    'float' => 'number',
                    'bool'  => 'boolean',
                    default => 'string',
                };
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}