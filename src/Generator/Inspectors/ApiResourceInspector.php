<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator\Inspectors;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final class ApiResourceInspector
{
    public function apply(array &$record, Route $route): void
    {
        $resource = $this->returnTypeResource($route);
        if ($resource === null) {
            return;
        }
        $shape = $this->shapeOf($resource);
        if ($shape === null) {
            return;
        }
        if (! isset($record['responses']['200'])) {
            $record['responses']['200'] = [
                'description' => 'OK',
                'mediaType'   => 'application/json',
                'schema'      => $shape,
                'example'     => null,
            ];
        }
    }

    private function returnTypeResource(Route $route): ?string
    {
        $action = $route->getActionName();
        if (! is_string($action) || ! str_contains($action, '@')) {
            return null;
        }
        [$class, $method] = explode('@', $action, 2);
        try {
            $r = (new ReflectionClass($class))->getMethod($method);
        } catch (Throwable) {
            return null;
        }
        $t = $r->getReturnType();
        if (! $t instanceof ReflectionNamedType) {
            return null;
        }
        $name = $t->getName();
        if (class_exists($name) && (is_subclass_of($name, JsonResource::class) || is_subclass_of($name, ResourceCollection::class))) {
            return $name;
        }

        return null;
    }

    /**
     * Best-effort: parse the source of `toArray()` for `'key' => ...` literals.
     *
     * Avoids instantiating Resource (which would require a real model). This
     * gives a schema sketch, not exact types.
     *
     * @return array<string, string>|null
     */
    private function shapeOf(string $class): ?array
    {
        try {
            $r = new ReflectionClass($class);
            if (! $r->hasMethod('toArray')) {
                return null;
            }
            $m = $r->getMethod('toArray');
            $file = $m->getFileName();
            if ($file === false || ! is_file($file)) {
                return null;
            }
            $lines = file($file);
            if ($lines === false) {
                return null;
            }
            $body = implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
        } catch (Throwable) {
            return null;
        }

        if (! preg_match_all("/'([^']+)'\s*=>/", $body, $matches)) {
            return null;
        }

        return array_fill_keys(array_unique($matches[1]), 'mixed');
    }
}
