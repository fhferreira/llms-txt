<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Mcp;

use RuntimeException;

/**
 * Read and validate an llms-mcp.json catalog from disk.
 */
final class CatalogLoader
{
    /**
     * @return array{spec_version:int, api:array<string,mixed>, tools:array<int, array<string,mixed>>, endpoints:array<int, array<string,mixed>>}
     */
    public static function load(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("llms-mcp.json not found at [{$path}]. Run `php artisan llms:generate` first.");
        }
        $raw = (string) file_get_contents($path);
        $doc = json_decode($raw, true);
        if (! is_array($doc) || ! isset($doc['tools']) || ! is_array($doc['tools'])) {
            throw new RuntimeException("llms-mcp.json at [{$path}] is missing a `tools` array.");
        }

        return [
            'spec_version' => (int) ($doc['spec_version'] ?? 1),
            'api'          => (array) ($doc['api']        ?? []),
            'tools'        => array_values((array) ($doc['tools']     ?? [])),
            'endpoints'    => array_values((array) ($doc['endpoints'] ?? [])),
        ];
    }
}