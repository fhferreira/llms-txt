<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator\Inspectors;

use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class ControllerDocBlockInspector
{
    public function apply(array &$record, Route $route): void
    {
        $method = $this->reflectMethod($route);
        if ($method === null) {
            return;
        }
        $doc = $method->getDocComment();
        if ($doc === false || $doc === '') {
            return;
        }

        [$summary, $description] = $this->parseDocBlock($doc);
        if ($record['summary'] === null && $summary !== '') {
            $record['summary'] = $summary;
        }
        if ($record['description'] === null && $description !== '') {
            $record['description'] = $description;
        }

        if ($record['tag'] === null && $record['controller'] !== null) {
            $record['tag'] = $record['controller'];
        }
    }

    private function reflectMethod(Route $route): ?ReflectionMethod
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
            $r = new ReflectionClass($class);
            if (! $r->hasMethod($method)) {
                return null;
            }

            return $r->getMethod($method);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0:string,1:string}  [summary, description]
     */
    private function parseDocBlock(string $doc): array
    {
        $lines = preg_split('/\R/', $doc) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $l = trim($line);
            $l = preg_replace('#^/\*+#', '', $l);
            $l = preg_replace('#\*+/$#', '', $l ?? '');
            $l = preg_replace('/^\*\s?/', '', $l ?? '');
            if ($l === null) {
                continue;
            }
            if (str_starts_with(trim($l), '@')) {
                break; // stop at first @-annotation block
            }
            $clean[] = $l;
        }
        $text = trim(implode("\n", $clean));
        if ($text === '') {
            return ['', ''];
        }
        $parts = preg_split('/\n\s*\n/', $text, 2) ?: [$text];
        $summary = trim($parts[0]);
        $description = trim($parts[1] ?? '');

        return [$summary, $description];
    }
}
