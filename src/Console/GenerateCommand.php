<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Console;

use Fhferreira\LlmsTxt\Generator\EndpointInspector;
use Fhferreira\LlmsTxt\Generator\FullTxtRenderer;
use Fhferreira\LlmsTxt\Generator\McpRenderer;
use Fhferreira\LlmsTxt\Generator\OpenApiOverlay;
use Fhferreira\LlmsTxt\Generator\RouteCollector;
use Fhferreira\LlmsTxt\Generator\TxtRenderer;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;

final class GenerateCommand extends Command
{
    protected $signature = 'llms:generate
                            {--out= : Override output path for llms.txt}
                            {--out-full= : Override output path for llms-full.txt}
                            {--out-mcp= : Override output path for llms-mcp.json}
                            {--overlay= : Path to an OpenAPI JSON overlay (overrides config)}
                            {--dry-run : Print to stdout instead of writing files}';

    protected $description = 'Generate llms.txt, llms-full.txt and llms-mcp.json for routes tagged with the configured middleware marker.';

    public function handle(Router $router): int
    {
        $marker      = (string) config('llms-txt.middleware_marker', 'llms');
        $overlayPath = (string) ($this->option('overlay') ?: config('llms-txt.openapi_overlay'));
        $outShort    = (string) ($this->option('out')      ?: config('llms-txt.output.llms_txt'));
        $outFull     = (string) ($this->option('out-full') ?: config('llms-txt.output.llms_full_txt'));
        $outMcp      = (string) ($this->option('out-mcp')  ?: config('llms-txt.output.llms_mcp_json'));

        $overlay   = OpenApiOverlay::loadOrNull($overlayPath);
        $routes    = (new RouteCollector($router, $marker))->collect();
        if ($routes->isEmpty()) {
            $this->components->error("No routes found with middleware marker [{$marker}]. Tag a route group with `->middleware('{$marker}')`.");

            return self::FAILURE;
        }

        $inspector = new EndpointInspector($overlay);
        $endpoints = $routes->map(fn ($r) => $inspector->inspect($r))->values();

        $short = (new TxtRenderer(config('llms-txt.api')))->render($endpoints, $overlay);
        $full  = (new FullTxtRenderer(config('llms-txt.api')))->render($endpoints, $overlay);
        $mcp   = (new McpRenderer(config('llms-txt.api')))->render($endpoints, $overlay);

        if ($this->option('dry-run')) {
            $this->line('--- llms.txt ---');
            $this->line($short);
            $this->line('--- llms-full.txt ---');
            $this->line($full);
            $this->line('--- llms-mcp.json ---');
            $this->line($mcp);

            return self::SUCCESS;
        }

        $this->writeFile($outShort, $short);
        $this->writeFile($outFull,  $full);
        if ($outMcp !== '') {
            $this->writeFile($outMcp, $mcp);
        }

        $this->components->info(sprintf(
            'Generated %d endpoints across %d paths.',
            $endpoints->count(),
            $endpoints->pluck('path')->unique()->count(),
        ));
        $this->components->twoColumnDetail('llms.txt',      $outShort);
        $this->components->twoColumnDetail('llms-full.txt', $outFull);
        if ($outMcp !== '') {
            $this->components->twoColumnDetail('llms-mcp.json', $outMcp);
        }

        return self::SUCCESS;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $contents);
    }
}
