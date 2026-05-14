<?php

declare(strict_types=1);

/**
 * Standalone preview of the renderers — no Laravel autoloader needed.
 *
 * Loads any OpenAPI 3 JSON file, synthesizes endpoint records that match what
 * EndpointInspector would emit at runtime, and runs the real TxtRenderer +
 * FullTxtRenderer to produce llms.txt and llms-full.txt.
 *
 * Useful for sanity-checking output format without a full Laravel install.
 *
 *   php preview/preview.php <openapi.json> [llms.txt] [llms-full.txt] [meta.json]
 *
 * Arguments
 *   1. openapi.json   — path to an OpenAPI 3 spec (required)
 *   2. llms.txt       — output path for short curated file       (default ./llms.txt)
 *   3. llms-full.txt  — output path for expanded reference       (default ./llms-full.txt)
 *   4. meta.json      — JSON file overriding header fields:
 *                       { title, description, version, base_url, auth.note, rate_limit_note }
 *                       (default: derived from the OpenAPI spec)
 */

namespace {

    require __DIR__ . '/preview_polyfill.php';
    require __DIR__ . '/../src/Generator/OpenApiOverlay.php';
    require __DIR__ . '/../src/Generator/TxtRenderer.php';
    require __DIR__ . '/../src/Generator/FullTxtRenderer.php';

    use Fhferreira\LlmsTxt\Generator\FullTxtRenderer;
    use Fhferreira\LlmsTxt\Generator\OpenApiOverlay;
    use Fhferreira\LlmsTxt\Generator\TxtRenderer;
    use Illuminate\Support\Collection;

    $overlayPath = $argv[1] ?? null;
    $outShort    = $argv[2] ?? getcwd() . '/llms.txt';
    $outFull     = $argv[3] ?? getcwd() . '/llms-full.txt';
    $metaPath    = $argv[4] ?? null;

    if (! $overlayPath) {
        fwrite(STDERR, "Usage: php preview/preview.php <openapi.json> [llms.txt] [llms-full.txt] [meta.json]\n");
        exit(2);
    }
    $overlay = OpenApiOverlay::loadOrNull($overlayPath);
    if (! $overlay) {
        fwrite(STDERR, "Cannot load OpenAPI from {$overlayPath}\n");
        exit(1);
    }

    $spec        = $overlay->spec();
    $specInfo    = $spec['info']    ?? [];
    $specServers = $spec['servers'] ?? [];
    $docBase     = $spec['x-doc-base-url'] ?? null;

    $meta = [
        'title'       => $specInfo['title']        ?? 'API',
        'description' => $specInfo['description']  ?? null,
        'version'     => $specInfo['version']      ?? null,
        'base_url'    => $specServers[0]['url']    ?? null,
        'auth' => [
            'note' => 'Send `Authorization: Bearer <token>` on every request.',
        ],
        'rate_limit_note' => null,
    ];

    if ($metaPath && is_file($metaPath)) {
        $override = json_decode((string) file_get_contents($metaPath), true);
        if (is_array($override)) {
            $meta = array_replace_recursive($meta, $override);
        }
    }

    $records = [];
    foreach (($spec['paths'] ?? []) as $path => $methods) {
        foreach ($methods as $method => $op) {
            if (! in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }
            $parameters = [];
            foreach (($op['parameters'] ?? []) as $p) {
                if (! isset($p['name'], $p['in'])) continue;
                $parameters[] = [
                    'name'        => (string) $p['name'],
                    'in'          => (string) $p['in'],
                    'required'    => (bool) ($p['required'] ?? false),
                    'type'        => $p['schema']['type'] ?? 'string',
                    'description' => (string) ($p['description'] ?? ''),
                    'example'     => null,
                ];
            }
            $reqBody = null;
            if (isset($op['requestBody']['content']) && is_array($op['requestBody']['content'])) {
                $mt   = array_key_first($op['requestBody']['content']);
                $body = $op['requestBody']['content'][$mt];
                $reqBody = [
                    'mediaType' => (string) $mt,
                    'schema'    => $body['schema'] ?? null,
                    'example'   => firstExample($body),
                ];
            }
            $responses = [];
            foreach (($op['responses'] ?? []) as $code => $r) {
                if (! is_array($r) || $code === '') continue;
                $mt = null; $schema = null; $example = null;
                if (isset($r['content']) && is_array($r['content'])) {
                    $mt      = array_key_first($r['content']);
                    $schema  = $r['content'][$mt]['schema']  ?? null;
                    $example = firstExample($r['content'][$mt] ?? []);
                }
                $responses[(string) $code] = [
                    'description' => (string) ($r['description'] ?? ''),
                    'mediaType'   => $mt,
                    'schema'      => $schema,
                    'example'     => $example,
                ];
            }
            $docUrl = $op['x-doc-url'] ?? null;
            if (! $docUrl && ! empty($op['x-doc-slug']) && $docBase) {
                $docUrl = rtrim((string) $docBase, '/') . '/' . $op['x-doc-slug'];
            }
            $records[] = [
                'method'      => strtoupper((string) $method),
                'methods'     => [strtoupper((string) $method)],
                'path'        => (string) $path,
                'name'        => null,
                'action'      => null,
                'controller'  => null,
                'tag'         => $op['tags'][0] ?? null,
                'summary'     => $op['summary']     ?? null,
                'description' => $op['description'] ?? null,
                'parameters'  => $parameters,
                'requestBody' => $reqBody,
                'responses'   => $responses,
                'auth'        => $meta['auth']['note'] ?? null,
                'rateLimit'   => null,
                'middleware'  => ['llms'],
                'docUrl'      => $docUrl,
            ];
        }
    }

    $endpoints = new Collection($records);
    $short     = (new TxtRenderer($meta))->render($endpoints, $overlay);
    $full      = (new FullTxtRenderer($meta))->render($endpoints, $overlay);

    file_put_contents($outShort, $short);
    file_put_contents($outFull,  $full);

    printf("Generated %d endpoints across %d paths.\n", $endpoints->count(), $endpoints->pluck('path')->unique()->count());
    printf("  llms.txt      : %s (%d bytes)\n", $outShort, strlen($short));
    printf("  llms-full.txt : %s (%d bytes)\n", $outFull,  strlen($full));

    function firstExample(array $content): mixed
    {
        if (! empty($content['example'])) return $content['example'];
        if (! empty($content['examples']) && is_array($content['examples'])) {
            $first = reset($content['examples']);

            return is_array($first) ? ($first['value'] ?? null) : $first;
        }

        return null;
    }
}
