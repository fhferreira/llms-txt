<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator;

use Illuminate\Support\Collection;

/**
 * Emit llms-mcp.json — machine-readable MCP tool catalog + endpoint detail.
 *
 * Top-level shape:
 *   {
 *     "spec_version": 1,
 *     "generated_at": "ISO8601",
 *     "api":      { "base_url": "...", "auth": {...} },
 *     "tools":    [ {name, description, method, path, scope, rate_limit, input_schema}, ... ],
 *     "endpoints":[ {method, path, operation, summary, parameters, request_body, responses}, ... ]
 *   }
 *
 * Handler FQCNs are never emitted — only public-shaped data ends up in this file.
 */
final class McpRenderer
{
    public const SPEC_VERSION = 1;

    /** @param array<string, mixed> $meta */
    public function __construct(private readonly array $meta) {}

    /**
     * @param Collection<int, array<string, mixed>> $endpoints
     */
    public function render(Collection $endpoints, ?OpenApiOverlay $overlay = null): string
    {
        $baseUrl = ! empty($this->meta['base_url']) ? (string) $this->meta['base_url']
                 : ($overlay && ! empty($overlay->servers()[0]['url']) ? (string) $overlay->servers()[0]['url'] : null);

        $doc = [
            'spec_version' => self::SPEC_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'api'          => array_filter([
                'title'       => $this->meta['title']       ?? null,
                'version'     => $this->meta['version']     ?? null,
                'description' => $this->meta['description'] ?? null,
                'base_url'    => $baseUrl,
                'auth'        => $this->normalizeAuth(),
            ], static fn ($v) => $v !== null && $v !== ''),
            'tools'        => $endpoints->map(fn (array $e) => $this->toTool($e))->values()->all(),
            'endpoints'    => $endpoints->map(fn (array $e) => $this->toEndpoint($e))->values()->all(),
        ];

        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ($json === false ? '{}' : $json) . "\n";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeAuth(): ?array
    {
        $auth = $this->meta['auth'] ?? null;
        if (! is_array($auth) || $auth === []) {
            return null;
        }

        return array_filter([
            'type'   => $auth['scheme'] ?? null,
            'header' => $auth['header'] ?? null,
            'note'   => $auth['note']   ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function toTool(array $e): array
    {
        $tool = [
            'name'         => $this->toolName($e),
            'description'  => $this->toolDescription($e),
            'method'       => $e['method'],
            'path'         => $e['path'],
        ];
        if (! empty($e['llmsAttribute']['scope'])) {
            $tool['scope'] = (string) $e['llmsAttribute']['scope'];
        }
        $rate = $this->parseRateLimit((string) ($e['rateLimit'] ?? ''));
        if ($rate !== null) {
            $tool['rate_limit'] = $rate;
        }
        $tool['input_schema'] = $this->inputSchema($e);

        return $tool;
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function toEndpoint(array $e): array
    {
        $endpoint = [
            'method'    => $e['method'],
            'path'      => $e['path'],
            'operation' => $e['name'] ?? null,
            'summary'   => $e['summary'] ?? null,
        ];
        if (! empty($e['description'])) {
            $endpoint['description'] = $e['description'];
        }
        $params = [];
        foreach ($e['parameters'] as $p) {
            $params[] = array_filter([
                'name'        => $p['name'],
                'in'          => $p['in'],
                'required'    => (bool) ($p['required'] ?? false),
                'type'        => $p['type'] ?? 'string',
                'description' => $p['description'] ?? null,
                'example'     => $p['example']     ?? null,
            ], static fn ($v) => $v !== null && $v !== '');
        }
        if ($params !== []) {
            $endpoint['parameters'] = $params;
        }
        if (! empty($e['requestBody'])) {
            $rb = $e['requestBody'];
            $endpoint['request_body'] = array_filter([
                'media_type' => $rb['mediaType'] ?? null,
                'example'    => $rb['example']   ?? null,
                'schema'     => $rb['schema']    ?? null,
            ], static fn ($v) => $v !== null && $v !== '');
        }
        if (! empty($e['responses'])) {
            $responses = [];
            foreach ($e['responses'] as $code => $r) {
                if ($code === '') continue;
                $responses[(string) $code] = array_filter([
                    'description' => $r['description'] ?? null,
                    'media_type'  => $r['mediaType']   ?? null,
                    'example'     => $r['example']    ?? null,
                    'schema'      => $r['schema']     ?? null,
                ], static fn ($v) => $v !== null && $v !== '');
            }
            if ($responses !== []) {
                $endpoint['responses'] = $responses;
            }
        }
        if (! empty($e['auth']))      $endpoint['auth']       = $e['auth'];
        if (! empty($e['rateLimit'])) $endpoint['rate_limit'] = $e['rateLimit'];
        if (! empty($e['docUrl']))    $endpoint['doc_url']    = $e['docUrl'];

        return array_filter($endpoint, static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Stable identifier, in priority order:
     *   1. #[Llms(name: '...')] attribute
     *   2. Route name (api.v3.orders.list → v3_orders_list, with leading `api_` stripped)
     *   3. METHOD + path tokens (GET /api/v3/{storename}/orders → v3_orders_list)
     *
     * @param array<string, mixed> $e
     */
    private function toolName(array $e): string
    {
        if (! empty($e['llmsAttribute']['name'])) {
            return self::snake((string) $e['llmsAttribute']['name']);
        }
        if (! empty($e['name'])) {
            return self::snake((string) $e['name']);
        }

        return self::snakeFromMethodPath((string) $e['method'], (string) $e['path']);
    }

    /**
     * @param array<string, mixed> $e
     */
    private function toolDescription(array $e): string
    {
        if (! empty($e['llmsAttribute']['description'])) {
            return (string) $e['llmsAttribute']['description'];
        }
        if (! empty($e['summary'])) {
            return (string) $e['summary'];
        }

        return $this->generateDescription((string) $e['method'], (string) $e['path']);
    }

    private function generateDescription(string $method, string $path): string
    {
        $resource = $this->lastNoun($path);
        $verb = match (strtoupper($method)) {
            'GET'    => str_ends_with($path, '/count') ? 'Count' : (preg_match('/\{[^}]+\}$/', $path) ? 'Get' : 'List'),
            'POST'   => 'Create',
            'PUT', 'PATCH' => 'Update',
            'DELETE' => 'Delete',
            default  => 'Call',
        };

        return trim("{$verb} {$resource}");
    }

    private function lastNoun(string $path): string
    {
        $parts = array_values(array_filter(explode('/', $path), static fn ($p) => $p !== ''));
        if ($parts === []) {
            return 'resource';
        }
        $last = (string) end($parts);
        $last = trim($last, '{}?');

        return str_replace(['_', '-'], ' ', $last);
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function inputSchema(array $e): array
    {
        // Working tree built incrementally so dot-paths produce nested objects
        // and `*` segments produce arrays. Finalised at the end into JSON Schema.
        $root = ['type' => 'object', 'properties' => [], 'required' => []];

        foreach ($e['parameters'] as $p) {
            $name = (string) $p['name'];
            if ($name === '') {
                continue;
            }
            [$leaf, $leafRequired] = $this->buildLeaf($p);
            $segments = explode('.', $name);
            $this->insertAt($root, $segments, $leaf, $leafRequired);
        }

        return $this->finalizeNode($root);
    }

    /**
     * Build the JSON Schema fragment for a single param entry.
     *
     * @return array{0: array<string, mixed>, 1: bool}  [schema, isRequired]
     */
    private function buildLeaf(array $p): array
    {
        if (! empty($p['rules']) && is_array($p['rules'])) {
            $built = RulesToJsonSchema::convert($p['rules']);
            $prop  = $built['schema'];
            $req   = (bool) $built['required'];
        } else {
            $prop = ['type' => $p['type'] ?? 'string'];
            $req  = ! empty($p['required']);
        }
        if (! empty($p['description'])) {
            $prop['description'] = (string) $p['description'];
        }
        if (! empty($p['in'])) {
            $prop['in'] = (string) $p['in'];
        }
        return [$prop, $req];
    }

    /**
     * Place a leaf schema into the working tree at the given dot-path.
     *
     * Tree shape: each node is `['type'=>'object', 'properties'=>[name=>node],
     * 'required'=>[]]` or `['type'=>'array', 'items'=>node]` or a leaf schema
     * (any other associative array).
     *
     * @param  array<int, string>  $segments
     * @param  array<string, mixed>  $leaf
     */
    private function insertAt(array &$node, array $segments, array $leaf, bool $isRequired): void
    {
        $head = array_shift($segments);
        if ($head === null) {
            return;
        }

        // Leaf placement
        if ($segments === []) {
            if ($head === '*') {
                $node['items'] = $leaf;
                return;
            }
            $node['properties'][$head] = $leaf;
            if ($isRequired) {
                $node['required'][] = $head;
            }
            return;
        }

        // Wildcard inside path: current node must be an array; recurse into its items.
        if ($head === '*') {
            if (! isset($node['items']) || ($node['items']['type'] ?? null) !== 'object') {
                $node['items'] = ['type' => 'object', 'properties' => [], 'required' => []];
            }
            $this->insertAt($node['items'], $segments, $leaf, $isRequired);
            return;
        }

        // Look ahead: next segment === '*' means this property is an array.
        $next = $segments[0];
        if ($next === '*') {
            if (! isset($node['properties'][$head]) || ($node['properties'][$head]['type'] ?? null) !== 'array') {
                $node['properties'][$head] = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [], 'required' => []]];
            }
            $this->insertAt($node['properties'][$head], $segments, $leaf, $isRequired);
            return;
        }

        // Plain nested object segment.
        if (! isset($node['properties'][$head]) || ($node['properties'][$head]['type'] ?? null) !== 'object') {
            $node['properties'][$head] = ['type' => 'object', 'properties' => [], 'required' => []];
        }
        $this->insertAt($node['properties'][$head], $segments, $leaf, $isRequired);
    }

    /**
     * Convert the working tree into JSON Schema (properties → object, required[] dedup).
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function finalizeNode(array $node): array
    {
        $type = $node['type'] ?? null;

        if ($type === 'object') {
            $finalProps = [];
            foreach ($node['properties'] as $name => $child) {
                $finalProps[$name] = $this->finalizeNode($child);
            }
            $out = [
                'type'                 => 'object',
                'properties'           => (object) $finalProps,
                'additionalProperties' => false,
            ];
            if (! empty($node['required'])) {
                $out['required'] = array_values(array_unique($node['required']));
            }
            return $out;
        }

        if ($type === 'array') {
            return [
                'type'  => 'array',
                'items' => isset($node['items']) ? $this->finalizeNode($node['items']) : (object) [],
            ];
        }

        // Leaf (already a JSON Schema fragment) — pass through.
        return $node;
    }

    /**
     * "Rate limit: 180,1" → ["requests" => 180, "per_minutes" => 1]
     * Returns null if unparseable.
     *
     * @return array{requests:int, per_minutes:int}|null
     */
    private function parseRateLimit(string $note): ?array
    {
        if (! preg_match('/(\d+)\s*,\s*(\d+)/', $note, $m)) {
            return null;
        }

        return ['requests' => (int) $m[1], 'per_minutes' => (int) $m[2]];
    }

    private static function snake(string $value): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9]+/', '_', $value);
        $value = (string) preg_replace('/(?<!^)([A-Z])/', '_$1', $value);
        $value = strtolower(trim($value, '_'));
        $value = (string) preg_replace('/_+/', '_', $value);

        return preg_replace('/^api_v(\d+)_/', 'v$1_', $value) ?? $value;
    }

    private static function snakeFromMethodPath(string $method, string $path): string
    {
        $tokens = [];
        foreach (explode('/', trim($path, '/')) as $seg) {
            if ($seg === '' || str_starts_with($seg, '{')) {
                continue;
            }
            $tokens[] = $seg;
        }
        $verb = match (strtoupper($method)) {
            'GET'    => str_ends_with($path, '/count') ? 'count' : (preg_match('/\{[^}]+\}$/', $path) ? 'get' : 'list'),
            'POST'   => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default  => strtolower($method),
        };
        if ($tokens === [] || end($tokens) !== $verb) {
            $tokens[] = $verb;
        }

        return self::snake(implode('_', $tokens));
    }
}