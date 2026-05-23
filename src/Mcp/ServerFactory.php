<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Mcp;

use Closure;
use RuntimeException;

/**
 * Build an `Mcp\Server` from an llms-mcp.json catalog. Shared by both
 * transports — stdio (artisan command) and HTTP (controller).
 *
 * The factory does not depend on the transport: the caller picks the
 * `Mcp\Server\Transport\…` instance and invokes `$server->run($transport)`.
 */
final class ServerFactory
{
    /**
     * @param  array{api?:array<string,mixed>, tools?:array<int, array<string,mixed>>}  $catalog
     */
    public static function build(array $catalog, RouteDispatcher $dispatcher): object
    {
        if (! class_exists(\Mcp\Server::class)) {
            throw new RuntimeException(
                'The `mcp/sdk` package is required. Install it with: composer require mcp/sdk'
            );
        }

        $name    = (string) ($catalog['api']['title']   ?? 'Laravel API');
        $version = (string) ($catalog['api']['version'] ?? '1.0.0');

        $builder = \Mcp\Server::builder()->setServerInfo($name, $version);

        foreach ((array) ($catalog['tools'] ?? []) as $tool) {
            $toolName = (string) ($tool['name'] ?? '');
            if ($toolName === '') {
                continue;
            }
            $builder->addTool(
                handler:     self::makeHandler($dispatcher, $tool),
                name:        $toolName,
                description: (string) ($tool['description'] ?? ''),
                inputSchema: (array)  ($tool['input_schema'] ?? ['type' => 'object', 'properties' => (object) []]),
            );
        }

        return $builder->build();
    }

    /**
     * @param  array<string, mixed>  $tool
     */
    private static function makeHandler(RouteDispatcher $dispatcher, array $tool): Closure
    {
        return static function (array $args = []) use ($dispatcher, $tool): array {
            $result = $dispatcher->dispatch($tool, $args);
            if ($result['status'] >= 400) {
                return [
                    'isError' => true,
                    'status'  => $result['status'],
                    'body'    => $result['body'],
                ];
            }

            return [
                'status' => $result['status'],
                'body'   => $result['body'],
            ];
        };
    }
}