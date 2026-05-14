<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator;

use Fhferreira\LlmsTxt\Http\Middleware\Llms as LlmsMiddleware;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;

final class RouteCollector
{
    public function __construct(
        private readonly Router $router,
        private readonly string $marker,
    ) {}

    /**
     * @return Collection<int, Route>
     */
    public function collect(): Collection
    {
        $markerClass = LlmsMiddleware::class;

        return collect($this->router->getRoutes()->getRoutes())
            ->filter(function (Route $route) use ($markerClass): bool {
                foreach ($route->gatherMiddleware() as $m) {
                    if (! is_string($m)) {
                        continue;
                    }
                    $name = strtok($m, ':') ?: $m;
                    if ($name === $this->marker || $name === $markerClass) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }
}
