<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Console;

use Fhferreira\LlmsTxt\Generator\EndpointInspector;
use Fhferreira\LlmsTxt\Generator\FullTxtRenderer;
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
                            {--overlay= : Path to an OpenAPI JSON overlay (overrides config)}
                            {--dry-run : Print to stdout instead of writing files}';

    protected $description = 'Generate llms.txt and llms-full.txt for routes tagged with the configured middleware marker.';

    public function handle(Router $router): int
    {
        $marker      = (string) config('llms-txt.middleware_marker', 'llms');
        $overlayPath = (string) ($this->option('overlay') ?: config('llms-txt.openapi_overlay'));
        $outShort    = (string) ($this->option('out')      ?: config('llms-txt.output.llms_txt'));
        $outFull     = (string) ($this->option('out-full') ?: config('llms-txt.output.llms_full_txt'));

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

        if ($this->option('dry-run')) {
            $this->line('--- llms.txt ---');
            $this->line($short);
            $this->line('--- llms-full.txt ---');
            $this->line($full);

            return self::SUCCESS;
        }

        $this->writeFile($outShort, $short);
        $this->writeFile($outFull,  $full);

        $this->components->info(sprintf(
            'Generated %d endpoints across %d paths.',
            $endpoints->count(),
            $endpoints->pluck('path')->unique()->count(),
        ));
        $this->components->twoColumnDetail('llms.txt',      $outShort);
        $this->components->twoColumnDetail('llms-full.txt', $outFull);

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
