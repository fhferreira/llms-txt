<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Http\Controllers;

use Fhferreira\LlmsTxt\Console\ServeMcpCommand;
use Fhferreira\LlmsTxt\Mcp\CatalogLoader;
use Fhferreira\LlmsTxt\Mcp\RouteDispatcher;
use Fhferreira\LlmsTxt\Mcp\ServerFactory;
use Fhferreira\LlmsTxt\Mcp\TokenResolverChain;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP entry point for the MCP server. Mount it in your routes file:
 *
 *   Route::any('/mcp', \Fhferreira\LlmsTxt\Http\Controllers\McpController::class)
 *       ->middleware(['throttle:60,1']);
 *
 * The default token strategy is **bearer passthrough**: whatever the MCP
 * client sends as `Authorization: Bearer …` is reused verbatim on every
 * internal Laravel dispatch. This lets a single MCP server serve many users —
 * each request carries its own token, just like calling the API directly.
 *
 * Override the passthrough by binding a resolver into the container:
 *
 *   $this->app->singleton(ServeMcpCommand::TOKEN_RESOLVER, fn () =>
 *       fn (array $tool, array $args): ?string => ...);
 *
 * Requires the host application to install:
 *   composer require mcp/sdk symfony/psr-http-message-bridge nyholm/psr7
 */
final class McpController
{
    public function __invoke(Request $request, HttpKernel $kernel, Container $container): Response
    {
        if (! class_exists(\Mcp\Server::class)) {
            return new Response(json_encode([
                'error' => 'mcp/sdk not installed. Run: composer require mcp/sdk',
            ]) ?: '', 500, ['Content-Type' => 'application/json']);
        }
        if (! class_exists(PsrHttpFactory::class)) {
            return new Response(json_encode([
                'error' => 'symfony/psr-http-message-bridge not installed. Run: composer require symfony/psr-http-message-bridge nyholm/psr7',
            ]) ?: '', 500, ['Content-Type' => 'application/json']);
        }

        $catalogPath = (string) config('llms-txt.output.llms_mcp_json');
        $catalog     = CatalogLoader::load($catalogPath);

        $staticToken = (string) config('llms-txt.mcp.bearer_token', env('LLMS_TXT_MCP_TOKEN', ''));
        $resolver    = TokenResolverChain::resolve(
            container:         $container,
            passthroughBearer: $request->bearerToken(),
            staticOverride:    $staticToken !== '' ? $staticToken : null,
        );
        $dispatcher = new RouteDispatcher($kernel, $resolver);
        $server     = ServerFactory::build($catalog, $dispatcher);

        [$psrFactory, $httpFoundationFactory] = $this->psrFactories();
        $psrRequest = $psrFactory->createRequest($request);

        $transport = new \Mcp\Server\Transport\StreamableHttpTransport($psrRequest);
        $psrResponse = $server->run($transport);

        return $httpFoundationFactory->createResponse($psrResponse);
    }

    /**
     * @return array{0: PsrHttpFactory, 1: HttpFoundationFactory}
     */
    private function psrFactories(): array
    {
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();

        return [
            new PsrHttpFactory($factory, $factory, $factory, $factory),
            new HttpFoundationFactory(),
        ];
    }
}