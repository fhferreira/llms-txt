<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator;

use Fhferreira\LlmsTxt\Generator\Inspectors\ApiResourceInspector;
use Fhferreira\LlmsTxt\Generator\Inspectors\ControllerDocBlockInspector;
use Fhferreira\LlmsTxt\Generator\Inspectors\FormRequestInspector;
use Fhferreira\LlmsTxt\Generator\Inspectors\InlineValidationInspector;
use Fhferreira\LlmsTxt\Generator\Inspectors\LlmsAttributeInspector;
use Fhferreira\LlmsTxt\Generator\Inspectors\MiddlewareInspector;
use Fhferreira\LlmsTxt\Generator\Inspectors\OpenApiOverlayInspector;
use Fhferreira\LlmsTxt\Generator\Inspectors\PathParameterInspector;
use Illuminate\Routing\Route;

final class EndpointInspector
{
    public function __construct(
        private readonly ?OpenApiOverlay $overlay = null,
    ) {}

    /**
     * @return array<string, mixed>  Endpoint record consumed by renderers.
     */
    public function inspect(Route $route): array
    {
        $methods = array_values(array_filter($route->methods(), fn ($m) => $m !== 'HEAD'));
        $method  = strtoupper($methods[0] ?? 'GET');
        $uri     = '/' . ltrim($route->uri(), '/');

        $record = [
            'method'        => $method,
            'methods'       => $methods,
            'path'          => $uri,
            'name'          => $route->getName(),
            'action'        => $route->getActionName(),
            'controller'    => $this->controllerShortName($route),
            'tag'           => null,
            'summary'       => null,
            'description'   => null,
            'parameters'    => [],          // [{name, in, required, type, description, example, rules?}]
            'requestBody'   => null,         // {mediaType, schema, example}
            'responses'     => [],           // status => {description, mediaType, example, schema}
            'auth'          => null,         // string note
            'rateLimit'     => null,         // string note
            'middleware'    => $route->gatherMiddleware(),
            'docUrl'        => null,
            'llmsAttribute' => null,         // {name, description, scope} from #[Llms]
        ];

        // 1. Middleware-derived auth + throttle
        (new MiddlewareInspector())->apply($record);

        // 2. Controller docblock summary/description + tag fallback
        (new ControllerDocBlockInspector())->apply($record, $route);

        // 3. #[Llms] attribute overrides
        (new LlmsAttributeInspector())->apply($record, $route);

        // 4. Path placeholders → required path parameters
        (new PathParameterInspector())->apply($record, $route);

        // 5. Form Request rules → parameters
        (new FormRequestInspector())->apply($record, $route);

        // 5b. Inline $request->validate([...]) / Validator::make(...) →
        //     parameters (only fires for fields the previous inspectors haven't
        //     already filled, so FormRequest / attribute / overlay stay
        //     authoritative).
        (new InlineValidationInspector())->apply($record, $route);

        // 6. API Resource return → response shape (best-effort)
        (new ApiResourceInspector())->apply($record, $route);

        // 7. OpenAPI overlay merge (most authoritative when present)
        if ($this->overlay) {
            (new OpenApiOverlayInspector($this->overlay))->apply($record);
        }

        return $record;
    }

    private function controllerShortName(Route $route): ?string
    {
        $action = $route->getActionName();
        if (! is_string($action) || ! str_contains($action, '@')) {
            return null;
        }
        [$class] = explode('@', $action, 2);
        $short = substr(strrchr($class, '\\') ?: '\\' . $class, 1);

        return preg_replace('/Controller$/', '', $short) ?: $short;
    }
}
