<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Console;

use Fhferreira\LlmsTxt\Mcp\CatalogLoader;
use Fhferreira\LlmsTxt\Mcp\RouteDispatcher;
use Fhferreira\LlmsTxt\Mcp\ServerFactory;
use Fhferreira\LlmsTxt\Mcp\TokenResolverChain;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Throwable;

/**
 * Boot a stdio MCP server backed by the routes catalogued in llms-mcp.json.
 *
 * Each `tools[]` entry is registered with the `mcp/sdk` builder and its
 * handler dispatches the underlying Laravel route in-process via
 * RouteDispatcher (no socket hop, full middleware chain runs).
 *
 * `mcp/sdk` is a `suggest` dependency — the command surfaces a friendly
 * message when it isn't installed instead of fataling at autoload time.
 */
final class ServeMcpCommand extends Command
{
    /**
     * Container binding key the host application uses to register a per-call
     * token resolver: `app()->bind(ServeMcpCommand::TOKEN_RESOLVER, fn () => fn (array $tool, array $args): ?string => …);`
     *
     * The resolver is invoked once per tool call so a single MCP process can
     * serve many users (typical SaaS), each with their own bearer token.
     */
    public const TOKEN_RESOLVER = 'llms-txt.mcp.token-resolver';

    protected $signature = 'llms:serve-mcp
                            {--catalog= : Path to llms-mcp.json (default: config output path)}
                            {--token= : Static bearer token for every call (overrides config, ignored if a resolver is bound)}';

    protected $description = 'Run a stdio MCP server that proxies the routes catalogued in llms-mcp.json.';

    public function handle(HttpKernel $kernel, Container $container): int
    {
        if (! class_exists(\Mcp\Server::class)) {
            $this->components->error(
                'The `mcp/sdk` package is required to run the MCP server. Install it with: composer require mcp/sdk'
            );

            return self::FAILURE;
        }

        $catalogPath = (string) ($this->option('catalog') ?: config('llms-txt.output.llms_mcp_json'));

        try {
            $catalog = CatalogLoader::load($catalogPath);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $staticOverride = (string) ($this->option('token') ?: (string) config('llms-txt.mcp.bearer_token', env('LLMS_TXT_MCP_TOKEN', '')));
        $dispatcher     = new RouteDispatcher($kernel, TokenResolverChain::resolve(
            container:         $container,
            passthroughBearer: null,
            staticOverride:    $staticOverride !== '' ? $staticOverride : null,
        ));
        $server     = ServerFactory::build($catalog, $dispatcher);
        $transport  = new \Mcp\Server\Transport\StdioTransport();

        $server->run($transport);

        return self::SUCCESS;
    }

}