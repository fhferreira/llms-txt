<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator\Inspectors;

use Fhferreira\LlmsTxt\Attributes\Llms;
use Illuminate\Routing\Route;
use ReflectionClass;
use Throwable;

final class LlmsAttributeInspector
{
    public function apply(array &$record, Route $route): void
    {
        $attr = $this->readAttribute($route);
        if ($attr === null) {
            return;
        }
        $record['llmsAttribute'] = [
            'name'        => $attr->name,
            'description' => $attr->description,
            'scope'       => $attr->scope,
        ];
        if ($attr->description !== null && $attr->description !== '' && $record['summary'] === null) {
            $record['summary'] = $attr->description;
        }
    }

    private function readAttribute(Route $route): ?Llms
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
            $attrs = $r->getMethod($method)->getAttributes(Llms::class);
            if ($attrs === []) {
                return null;
            }

            return $attrs[0]->newInstance();
        } catch (Throwable) {
            return null;
        }
    }
}