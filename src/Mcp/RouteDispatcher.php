<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Mcp;

use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Throwable;

/**
 * Translate MCP tool call args into an internal Laravel HTTP request and
 * dispatch it through the kernel so the full middleware chain (auth, throttle,
 * CORS, …) still runs. Returns the decoded JSON body when the response is
 * JSON, raw body otherwise.
 *
 * Args are routed by the `in` extension we emit on each input_schema property:
 *   { "in": "path"  } → path placeholder
 *   { "in": "query" } → query string
 *   { "in": "body"  } → JSON body
 *
 * Properties without an `in:` hint default to query for GET/HEAD/DELETE and
 * body for everything else, mirroring how FormRequestInspector classifies
 * unknown params.
 */
final class RouteDispatcher
{
    /**
     * @param  Closure|null  $tokenResolver  invoked as `($tool, $args): ?string` per call.
     *                                        Returning a non-empty string sets `Authorization: Bearer …`
     *                                        on the internal request; returning null/empty leaves it off.
     *                                        Per-call resolution lets a single MCP process serve many
     *                                        users — the host app maps the caller (session, user, etc.)
     *                                        to the right token at dispatch time.
     */
    public function __construct(
        private readonly HttpKernel $kernel,
        private readonly ?Closure $tokenResolver = null,
    ) {}

    /**
     * Convenience constructor for the common single-token case
     * (e.g. local dev, static service-account token).
     */
    public static function withStaticToken(HttpKernel $kernel, ?string $token): self
    {
        return new self(
            $kernel,
            $token !== null && $token !== ''
                ? static fn (): string => (string) $token
                : null,
        );
    }

    /**
     * @param  array<string, mixed>  $tool   one entry from llms-mcp.json `tools[]`
     * @param  array<string, mixed>  $args   args as passed by the MCP client
     * @return array{status:int, body:mixed, headers:array<string, array<int,string>>}
     */
    public function dispatch(array $tool, array $args): array
    {
        $method = strtoupper((string) ($tool['method'] ?? 'GET'));
        $path   = (string) ($tool['path']   ?? '/');
        $props  = (array) ($tool['input_schema']['properties'] ?? []);

        [$pathArgs, $queryArgs, $bodyArgs] = $this->splitArgs($method, $props, $args);

        $uri = $this->substitutePath($path, $pathArgs);

        $token   = $this->resolveToken($tool, $args);
        $request = $this->buildRequest($method, $uri, $queryArgs, $bodyArgs, $token);

        try {
            $response = $this->kernel->handle($request);
        } catch (Throwable $e) {
            return [
                'status'  => 500,
                'body'    => ['error' => $e->getMessage()],
                'headers' => [],
            ];
        }

        $raw         = (string) $response->getContent();
        $contentType = (string) $response->headers->get('Content-Type', '');
        $body        = $this->decodeIfJson($raw, $contentType);

        return [
            'status'  => $response->getStatusCode(),
            'body'    => $body,
            'headers' => $response->headers->all(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  array<string, mixed>                 $args
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<string,mixed>}  [path, query, body]
     */
    private function splitArgs(string $method, array $properties, array $args): array
    {
        $defaultIn = in_array($method, ['GET', 'HEAD', 'DELETE'], true) ? 'query' : 'body';
        $path = $query = $body = [];

        foreach ($args as $name => $value) {
            $in = (string) ($properties[$name]['in'] ?? $defaultIn);
            match ($in) {
                'path'   => $path[$name]  = $value,
                'query'  => $query[$name] = $value,
                default  => $body[$name]  = $value,
            };
        }

        return [$path, $query, $body];
    }

    /**
     * @param  array<string, mixed>  $pathArgs
     */
    private function substitutePath(string $path, array $pathArgs): string
    {
        return (string) preg_replace_callback(
            '/\{([^}?]+)(\??)\}/',
            static function (array $m) use (&$pathArgs): string {
                $name     = $m[1];
                $optional = $m[2] === '?';
                if (! array_key_exists($name, $pathArgs)) {
                    return $optional ? '' : '{' . $name . '}';
                }
                $value = (string) $pathArgs[$name];
                unset($pathArgs[$name]);

                return rawurlencode($value);
            },
            $path,
        );
    }

    /**
     * @param  array<string, mixed>  $tool
     * @param  array<string, mixed>  $args
     */
    private function resolveToken(array $tool, array $args): ?string
    {
        if ($this->tokenResolver === null) {
            return null;
        }
        $token = ($this->tokenResolver)($tool, $args);
        if (! is_string($token) || $token === '') {
            return null;
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    private function buildRequest(string $method, string $uri, array $query, array $body, ?string $bearerToken): Request
    {
        $uri = '/' . ltrim($uri, '/');

        $server = [
            'REQUEST_METHOD' => $method,
            'CONTENT_TYPE'   => 'application/json',
            'HTTP_ACCEPT'    => 'application/json',
        ];
        if ($bearerToken !== null && $bearerToken !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;
        }

        $content = $body !== [] ? (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $request = Request::create(
            $uri,
            $method,
            $query,
            cookies: [],
            files: [],
            server: $server,
            content: $content,
        );
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
            $request->setJson(new \Symfony\Component\HttpFoundation\InputBag((array) (json_decode($content, true) ?? [])));
        }

        return $request;
    }

    private function decodeIfJson(string $raw, string $contentType): mixed
    {
        if ($raw === '') {
            return null;
        }
        if (! str_contains(strtolower($contentType), 'json')) {
            return $raw;
        }
        $decoded = json_decode($raw, true);

        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $raw : $decoded;
    }
}