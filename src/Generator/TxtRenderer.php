<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator;

use Illuminate\Support\Collection;

/**
 * Short curated llms.txt (https://llmstxt.org/).
 *
 * Structure:
 *   # {title}
 *   > {tagline}
 *   ## Overview
 *   ## Authentication
 *   ## Endpoints
 *     ### {category}
 *     - METHOD /path — summary
 */
final class TxtRenderer
{
    /** @param array<string, mixed> $meta */
    public function __construct(private readonly array $meta) {}

    /**
     * @param Collection<int, array<string, mixed>> $endpoints
     */
    public function render(Collection $endpoints, ?OpenApiOverlay $overlay = null): string
    {
        $title       = $this->meta['title']       ?? 'API';
        $description = $this->meta['description'] ?? '';
        $version     = $this->meta['version']     ?? null;
        $baseUrl     = $this->resolveBaseUrl($overlay);
        $authNote    = $this->meta['auth']['note'] ?? null;
        $rateNote    = $this->meta['rate_limit_note'] ?? null;

        $lines = [];
        $lines[] = "# {$title}";
        $lines[] = '';
        if ($description !== '') {
            $lines[] = "> {$description}";
            $lines[] = '';
        }

        $lines[] = '## Overview';
        if ($version)  $lines[] = "- **Version**: {$version}";
        if ($baseUrl)  $lines[] = "- **Base URL**: `{$baseUrl}`";
        $lines[] = '- **Format**: JSON (`application/json`)';
        $lines[] = '';

        $lines[] = '## Authentication';
        if ($authNote) {
            $lines[] = $authNote;
        }
        $lines[] = '';

        $lines[] = '## Rate limits';
        if ($rateNote) {
            $lines[] = $rateNote;
        }
        $lines[] = '';

        $lines[] = '## Endpoints';
        $lines[] = '';
        $grouped = $endpoints->groupBy(fn ($e) => $e['tag'] ?: 'Other')->sortKeys();
        foreach ($grouped as $tag => $group) {
            $lines[] = "### {$tag}";
            foreach ($group as $e) {
                $method  = $e['method'];
                $path    = $e['path'];
                $summary = $e['summary'] ?: $this->fallbackSummary($e);
                $line    = "- `{$method} {$path}` — {$summary}";
                if (! empty($e['docUrl'])) {
                    $line .= " ([docs]({$e['docUrl']}))";
                }
                $lines[] = $line;
            }
            $lines[] = '';
        }

        $lines[] = '## See also';
        $lines[] = '- `llms-full.txt` — same endpoints with request parameters, request body schema, and response examples.';
        $lines[] = '';

        return rtrim(implode("\n", $lines)) . "\n";
    }

    private function fallbackSummary(array $e): string
    {
        $action = (string) ($e['action'] ?? '');
        if ($action !== '' && str_contains($action, '@')) {
            return $action;
        }

        return 'No summary available.';
    }

    private function resolveBaseUrl(?OpenApiOverlay $overlay): ?string
    {
        if (! empty($this->meta['base_url'])) {
            return (string) $this->meta['base_url'];
        }
        if ($overlay && ! empty($overlay->servers()[0]['url'])) {
            return (string) $overlay->servers()[0]['url'];
        }

        return null;
    }
}
