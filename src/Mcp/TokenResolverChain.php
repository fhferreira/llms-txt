<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Mcp;

use Closure;
use Fhferreira\LlmsTxt\Console\ServeMcpCommand;
use Illuminate\Contracts\Container\Container;

/**
 * Resolution chain used by both transports.
 *
 * Priority order:
 *   1. `ServeMcpCommand::TOKEN_RESOLVER` container binding — host app wins.
 *   2. Pass-through bearer (the token on the incoming MCP HTTP request).
 *   3. Static config / env token.
 *   4. Nothing — no Authorization header is sent.
 *
 * Stdio passes `passthroughBearer = null` (no incoming HTTP request). HTTP
 * passes `$request->bearerToken()` so the call to the underlying Laravel
 * route inherits whatever the MCP client was authenticated with.
 */
final class TokenResolverChain
{
    public static function resolve(
        Container $container,
        ?string $passthroughBearer = null,
        ?string $staticOverride = null,
    ): ?Closure {
        if ($container->bound(ServeMcpCommand::TOKEN_RESOLVER)) {
            $resolver = $container->make(ServeMcpCommand::TOKEN_RESOLVER);
            if ($resolver instanceof Closure) {
                return $resolver;
            }
            if (is_callable($resolver)) {
                return Closure::fromCallable($resolver);
            }
        }

        $passthrough = (string) ($passthroughBearer ?? '');
        if ($passthrough !== '') {
            return static fn (): string => $passthrough;
        }

        $static = (string) ($staticOverride ?? '');
        if ($static !== '') {
            return static fn (): string => $static;
        }

        return null;
    }
}