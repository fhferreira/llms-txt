<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator;

use Illuminate\Support\Collection;

/**
 * Expanded llms-full.txt — per-endpoint reference.
 *
 * Each endpoint section:
 *   ## METHOD /path — summary
 *   description...
 *   ### Parameters
 *   ### Request body
 *   ### Responses
 */
final class FullTxtRenderer
{
    /** @param array<string, mixed> $meta */
    public function __construct(private readonly array $meta) {}

    /**
     * @param Collection<int, array<string, mixed>> $endpoints
     */
    public function render(Collection $endpoints, ?OpenApiOverlay $overlay = null): string
    {
        $title    = $this->meta['title'] ?? 'API';
        $baseUrl  = ! empty($this->meta['base_url']) ? (string) $this->meta['base_url']
                  : ($overlay && ! empty($overlay->servers()[0]['url']) ? (string) $overlay->servers()[0]['url'] : null);

        $out = [];
        $out[] = "# {$title} — Full Reference";
        $out[] = '';
        if (! empty($this->meta['description'])) {
            $out[] = "> {$this->meta['description']}";
            $out[] = '';
        }
        if ($baseUrl) {
            $out[] = "**Base URL**: `{$baseUrl}`";
            $out[] = '';
        }
        if (! empty($this->meta['auth']['note'])) {
            $out[] = '**Authentication**: ' . $this->meta['auth']['note'];
            $out[] = '';
        }

        $out[] = '---';
        $out[] = '';

        $grouped = $endpoints->groupBy(fn ($e) => $e['tag'] ?: 'Other')->sortKeys();
        foreach ($grouped as $tag => $group) {
            $out[] = "## {$tag}";
            $out[] = '';
            foreach ($group as $e) {
                $out[] = $this->renderEndpoint($e);
            }
        }

        return rtrim(implode("\n", $out)) . "\n";
    }

    /**
     * @param array<string, mixed> $e
     */
    private function renderEndpoint(array $e): string
    {
        $lines = [];
        $heading = "### `{$e['method']} {$e['path']}`";
        if (! empty($e['summary'])) {
            $heading .= " — {$e['summary']}";
        }
        $lines[] = $heading;
        $lines[] = '';

        if (! empty($e['description'])) {
            $lines[] = $e['description'];
            $lines[] = '';
        }

        $meta = [];
        if (! empty($e['name']))      $meta[] = "**Operation**: `{$e['name']}`";
        if (! empty($e['auth']))      $meta[] = "**Auth**: {$e['auth']}";
        if (! empty($e['rateLimit'])) $meta[] = "**Rate limit**: {$e['rateLimit']}";
        if (! empty($e['docUrl']))    $meta[] = "**Docs**: [{$e['docUrl']}]({$e['docUrl']})";
        if ($meta !== []) {
            foreach ($meta as $m) $lines[] = "- {$m}";
            $lines[] = '';
        }

        // Parameters
        $paramsByIn = collect($e['parameters'])->groupBy('in');
        foreach (['path', 'query', 'header', 'cookie', 'body'] as $in) {
            $g = $paramsByIn->get($in);
            if (! $g || $g->isEmpty()) continue;
            $lines[] = '**' . ucfirst($in) . ' parameters**';
            $lines[] = '';
            $lines[] = '| Name | Type | Required | Description |';
            $lines[] = '|---|---|---|---|';
            foreach ($g as $p) {
                $name = $p['name'];
                $type = $p['type'] ?? 'string';
                $req  = empty($p['required']) ? '' : '**required**';
                $desc = trim((string) ($p['description'] ?? ''));
                $desc = str_replace(['|', "\n"], ['\\|', ' '], $desc);
                $lines[] = "| `{$name}` | {$type} | {$req} | {$desc} |";
            }
            $lines[] = '';
        }

        // Request body
        if (! empty($e['requestBody'])) {
            $rb = $e['requestBody'];
            $lines[] = '**Request body**' . (isset($rb['mediaType']) ? " (`{$rb['mediaType']}`)" : '');
            $lines[] = '';
            if (! empty($rb['example'])) {
                $lines[] = '```json';
                $lines[] = $this->prettyJson($rb['example']);
                $lines[] = '```';
                $lines[] = '';
            } elseif (! empty($rb['schema'])) {
                $lines[] = '```json';
                $lines[] = $this->prettyJson($rb['schema']);
                $lines[] = '```';
                $lines[] = '';
            }
        }

        // Responses
        if (! empty($e['responses'])) {
            $lines[] = '**Responses**';
            $lines[] = '';
            foreach ($e['responses'] as $code => $r) {
                if ($code === '') continue;
                $desc = trim((string) ($r['description'] ?? ''));
                $head = "- **{$code}**" . ($r['mediaType'] ? " (`{$r['mediaType']}`)" : '') . ($desc !== '' ? " — {$desc}" : '');
                $lines[] = $head;
                if (! empty($r['example'])) {
                    $lines[] = '';
                    $lines[] = '```json';
                    $lines[] = $this->prettyJson($r['example']);
                    $lines[] = '```';
                }
            }
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function prettyJson(mixed $value): string
    {
        if (is_string($value)) {
            // already serialized?
            $decoded = json_decode($value, true);
            if ($decoded !== null) {
                $value = $decoded;
            }
        }
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '/* unserializable */' : $json;
    }
}
