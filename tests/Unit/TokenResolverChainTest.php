<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Tests\Unit;

use Closure;
use Fhferreira\LlmsTxt\Console\ServeMcpCommand;
use Fhferreira\LlmsTxt\Mcp\TokenResolverChain;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TokenResolverChainTest extends TestCase
{
    private function container(): Container
    {
        $c = new Container();
        Container::setInstance($c);

        return $c;
    }

    #[Test]
    public function container_binding_wins_over_passthrough_and_static(): void
    {
        $c = $this->container();
        $c->bind(ServeMcpCommand::TOKEN_RESOLVER, fn () => fn () => 'from-binding');

        $resolver = TokenResolverChain::resolve($c, passthroughBearer: 'from-passthrough', staticOverride: 'from-static');

        self::assertInstanceOf(Closure::class, $resolver);
        self::assertSame('from-binding', ($resolver)([], []));
    }

    #[Test]
    public function passthrough_bearer_wins_over_static_when_no_binding(): void
    {
        $c = $this->container();

        $resolver = TokenResolverChain::resolve($c, passthroughBearer: 'from-passthrough', staticOverride: 'from-static');

        self::assertInstanceOf(Closure::class, $resolver);
        self::assertSame('from-passthrough', ($resolver)([], []));
    }

    #[Test]
    public function static_override_is_used_when_no_binding_and_no_passthrough(): void
    {
        $c = $this->container();

        $resolver = TokenResolverChain::resolve($c, passthroughBearer: null, staticOverride: 'from-static');

        self::assertInstanceOf(Closure::class, $resolver);
        self::assertSame('from-static', ($resolver)([], []));
    }

    #[Test]
    public function returns_null_when_nothing_configured(): void
    {
        $c = $this->container();

        $resolver = TokenResolverChain::resolve($c, passthroughBearer: null, staticOverride: null);

        self::assertNull($resolver);
    }

    #[Test]
    public function empty_passthrough_string_is_treated_as_no_passthrough(): void
    {
        $c = $this->container();

        $resolver = TokenResolverChain::resolve($c, passthroughBearer: '', staticOverride: 'from-static');

        self::assertSame('from-static', ($resolver)([], []));
    }
}