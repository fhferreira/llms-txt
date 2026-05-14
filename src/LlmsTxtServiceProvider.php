<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt;

use Fhferreira\LlmsTxt\Console\CleanupLocalCommand;
use Fhferreira\LlmsTxt\Console\GenerateCommand;
use Fhferreira\LlmsTxt\Http\Middleware\Llms as LlmsMiddleware;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class LlmsTxtServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/llms-txt.php', 'llms-txt');
    }

    public function boot(): void
    {
        $marker = (string) config('llms-txt.middleware_marker', 'llms');

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware($marker, LlmsMiddleware::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCommand::class,
                CleanupLocalCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/llms-txt.php' => config_path('llms-txt.php'),
            ], 'llms-txt-config');
        }
    }
}
