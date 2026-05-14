<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator\Inspectors;

final class MiddlewareInspector
{
    public function apply(array &$record): void
    {
        $auth = [];
        $rate = null;
        foreach ($record['middleware'] as $m) {
            if (! is_string($m)) {
                continue;
            }
            if ($m === 'auth' || str_starts_with($m, 'auth:')) {
                $guards = substr($m, 5) ?: 'web';
                $auth[] = "Auth required (guard: {$guards})";
            }
            if ($m === 'auth.basic' || str_starts_with($m, 'auth.basic')) {
                $auth[] = 'HTTP Basic auth';
            }
            if (str_starts_with($m, 'throttle:')) {
                $rate = 'Rate limit: ' . substr($m, 9);
            }
        }
        if ($auth !== []) {
            $record['auth'] = implode('; ', array_unique($auth));
        }
        if ($rate !== null) {
            $record['rateLimit'] = $rate;
        }
    }
}
