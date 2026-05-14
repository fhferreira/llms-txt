<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator\Inspectors;

use Fhferreira\LlmsTxt\Generator\OpenApiOverlay;

final class OpenApiOverlayInspector
{
    public function __construct(private readonly OpenApiOverlay $overlay) {}

    public function apply(array &$record): void
    {
        $op = $this->overlay->findOperation($record['path'], $record['method']);
        if ($op === null) {
            return;
        }

        if (empty($record['summary'])     && ! empty($op['summary']))     $record['summary']     = (string) $op['summary'];
        if (empty($record['description']) && ! empty($op['description'])) $record['description'] = (string) $op['description'];
        if (empty($record['tag'])         && ! empty($op['tags'][0]))     $record['tag']         = (string) $op['tags'][0];

        // Doc URL resolution, in priority order:
        //   1. Per-operation `x-doc-url` (full URL).
        //   2. Per-operation `x-doc-slug` + spec-level `x-doc-base-url`.
        //   3. Per-operation `x-doc-slug` + config `llms-txt.doc_url_prefix`.
        if (! empty($op['x-doc-url'])) {
            $record['docUrl'] = (string) $op['x-doc-url'];
        } elseif (! empty($op['x-doc-slug'])) {
            $prefix = $this->overlay->spec()['x-doc-base-url']
                ?? (function_exists('config') ? config('llms-txt.doc_url_prefix') : null);
            if ($prefix) {
                $record['docUrl'] = rtrim((string) $prefix, '/') . '/' . $op['x-doc-slug'];
            }
        }

        // Parameters
        $byKey = collect($record['parameters'])->keyBy(fn ($p) => $p['in'] . ':' . $p['name']);
        foreach (($op['parameters'] ?? []) as $p) {
            if (! is_array($p) || empty($p['name']) || empty($p['in'])) {
                continue;
            }
            $key  = $p['in'] . ':' . $p['name'];
            $type = $this->schemaType($p['schema'] ?? []);
            $byKey[$key] = [
                'name'        => (string) $p['name'],
                'in'          => (string) $p['in'],
                'required'    => (bool) ($p['required'] ?? false),
                'type'        => $type,
                'description' => (string) ($p['description'] ?? ($byKey[$key]['description'] ?? '')),
                'example'     => $this->firstExample($p),
            ];
        }
        $record['parameters'] = array_values($byKey->toArray());

        // Request body
        if (isset($op['requestBody']['content']) && is_array($op['requestBody']['content'])) {
            $mediaType = array_key_first($op['requestBody']['content']);
            $body      = $op['requestBody']['content'][$mediaType] ?? [];
            $record['requestBody'] = [
                'mediaType' => (string) $mediaType,
                'schema'    => $body['schema']   ?? null,
                'example'   => $this->pickExample($body),
            ];
        }

        // Responses
        foreach (($op['responses'] ?? []) as $code => $resp) {
            if (! is_array($resp)) {
                continue;
            }
            $mediaType = null;
            $schema    = null;
            $example   = null;
            if (isset($resp['content']) && is_array($resp['content'])) {
                $mediaType = array_key_first($resp['content']);
                $schema    = $resp['content'][$mediaType]['schema'] ?? null;
                $example   = $this->pickExample($resp['content'][$mediaType] ?? []);
            }
            $record['responses'][(string) $code] = [
                'description' => (string) ($resp['description'] ?? ''),
                'mediaType'   => $mediaType,
                'schema'      => $schema,
                'example'     => $example,
            ];
        }
    }

    private function schemaType(array $schema): string
    {
        if (isset($schema['type'])) {
            return (string) $schema['type'];
        }
        if (isset($schema['$ref'])) {
            return '$ref:' . $schema['$ref'];
        }

        return 'mixed';
    }

    private function firstExample(array $param): mixed
    {
        if (! empty($param['examples'])) {
            $first = is_array($param['examples']) ? reset($param['examples']) : null;
            if (is_array($first)) {
                return $first['value'] ?? null;
            }
        }
        return $param['example'] ?? ($param['schema']['examples'][0] ?? null);
    }

    private function pickExample(array $content): mixed
    {
        if (! empty($content['example'])) {
            return $content['example'];
        }
        if (! empty($content['examples']) && is_array($content['examples'])) {
            $first = reset($content['examples']);

            return is_array($first) ? ($first['value'] ?? null) : $first;
        }

        return null;
    }
}
