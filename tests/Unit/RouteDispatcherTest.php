<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Tests\Unit;

use Fhferreira\LlmsTxt\Mcp\RouteDispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteDispatcherTest extends TestCase
{
    /**
     * In-memory kernel that captures the Request passed to handle() so we can
     * inspect path / query / body routing without spinning up Laravel.
     */
    private function fakeKernel(callable $assert, mixed $responseBody = ['ok' => true], int $status = 200, string $contentType = 'application/json'): HttpKernel
    {
        return new class($assert, $responseBody, $status, $contentType) implements HttpKernel
        {
            public function __construct(
                private $assert,
                private mixed $body,
                private int $status,
                private string $contentType,
            ) {}

            public function handle($request): Response
            {
                ($this->assert)($request);
                $payload = is_string($this->body) ? $this->body : (string) json_encode($this->body);

                return new Response($payload, $this->status, ['Content-Type' => $this->contentType]);
            }

            public function terminate($request, $response): void {}

            public function bootstrap(): void {}

            public function getApplication(): mixed { return null; }
        };
    }

    private function tool(array $overrides = []): array
    {
        return array_replace([
            'name'         => 'orders_list',
            'description'  => 'List orders',
            'method'       => 'GET',
            'path'         => '/api/v3/{storename}/orders',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'storename' => ['type' => 'string',  'in' => 'path'],
                    'limit'     => ['type' => 'integer', 'in' => 'query'],
                ],
            ],
        ], $overrides);
    }

    #[Test]
    public function path_args_substitute_placeholders_and_query_args_go_to_query_string(): void
    {
        $captured = null;
        $kernel = $this->fakeKernel(function (Request $req) use (&$captured) { $captured = $req; });

        $result = (new RouteDispatcher($kernel))->dispatch($this->tool(), [
            'storename' => 'demoshop',
            'limit'     => 25,
        ]);

        self::assertSame('/api/v3/demoshop/orders', $captured->getPathInfo());
        self::assertSame(25, $captured->query('limit'));
        self::assertSame('GET', $captured->getMethod());
        self::assertSame(200, $result['status']);
        self::assertSame(['ok' => true], $result['body']);
    }

    #[Test]
    public function body_args_are_json_encoded_for_post_requests(): void
    {
        $captured = null;
        $kernel = $this->fakeKernel(function (Request $req) use (&$captured) { $captured = $req; });

        (new RouteDispatcher($kernel))->dispatch(
            $this->tool([
                'method' => 'POST',
                'path'   => '/api/v3/{storename}/orders',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'storename' => ['type' => 'string', 'in' => 'path'],
                        'customer'  => ['type' => 'string', 'in' => 'body'],
                        'total'     => ['type' => 'number', 'in' => 'body'],
                    ],
                ],
            ]),
            ['storename' => 'demoshop', 'customer' => 'Ada', 'total' => 42.5],
        );

        self::assertSame('POST', $captured->getMethod());
        self::assertSame('/api/v3/demoshop/orders', $captured->getPathInfo());
        self::assertSame('application/json', $captured->headers->get('Content-Type'));
        self::assertSame('Ada', $captured->json('customer'));
        self::assertSame(42.5, $captured->json('total'));
    }

    #[Test]
    public function static_token_helper_sets_authorization_header(): void
    {
        $captured = null;
        $kernel = $this->fakeKernel(function (Request $req) use (&$captured) { $captured = $req; });

        RouteDispatcher::withStaticToken($kernel, 'tkn-abc')->dispatch($this->tool(), ['storename' => 'demoshop']);

        self::assertSame('Bearer tkn-abc', $captured->headers->get('Authorization'));
    }

    #[Test]
    public function token_resolver_is_invoked_per_call_with_tool_and_args(): void
    {
        $seen     = [];
        $captured = [];
        $kernel = $this->fakeKernel(function (Request $req) use (&$captured) { $captured[] = $req; });

        $resolver = function (array $tool, array $args) use (&$seen): ?string {
            $seen[] = ['tool' => $tool['name'], 'shop' => $args['storename']];

            return 'tkn-for-' . $args['storename'];
        };

        $dispatcher = new RouteDispatcher($kernel, $resolver);
        $dispatcher->dispatch($this->tool(), ['storename' => 'shop-a']);
        $dispatcher->dispatch($this->tool(), ['storename' => 'shop-b']);

        self::assertSame('Bearer tkn-for-shop-a', $captured[0]->headers->get('Authorization'));
        self::assertSame('Bearer tkn-for-shop-b', $captured[1]->headers->get('Authorization'));
        self::assertSame([
            ['tool' => 'orders_list', 'shop' => 'shop-a'],
            ['tool' => 'orders_list', 'shop' => 'shop-b'],
        ], $seen);
    }

    #[Test]
    public function resolver_returning_null_omits_authorization_header(): void
    {
        $captured = null;
        $kernel = $this->fakeKernel(function (Request $req) use (&$captured) { $captured = $req; });

        $dispatcher = new RouteDispatcher($kernel, fn () => null);
        $dispatcher->dispatch($this->tool(), ['storename' => 'demoshop']);

        self::assertFalse($captured->headers->has('Authorization'));
    }

    #[Test]
    public function path_placeholders_are_url_encoded(): void
    {
        $captured = null;
        $kernel = $this->fakeKernel(function (Request $req) use (&$captured) { $captured = $req; });

        (new RouteDispatcher($kernel))->dispatch($this->tool(), ['storename' => 'shop with space/extra']);

        self::assertSame('/api/v3/shop%20with%20space%2Fextra/orders', $captured->getPathInfo());
    }

    #[Test]
    public function non_2xx_responses_pass_through_status_unchanged(): void
    {
        $kernel = $this->fakeKernel(function () {}, ['error' => 'not found'], 404);

        $result = (new RouteDispatcher($kernel))->dispatch($this->tool(), ['storename' => 'demoshop']);

        self::assertSame(404, $result['status']);
        self::assertSame(['error' => 'not found'], $result['body']);
    }

    #[Test]
    public function non_json_body_is_returned_as_raw_string(): void
    {
        $kernel = $this->fakeKernel(function () {}, 'plain text response', 200, 'text/plain');

        $result = (new RouteDispatcher($kernel))->dispatch($this->tool(), ['storename' => 'demoshop']);

        self::assertSame('plain text response', $result['body']);
    }

    #[Test]
    public function unknown_args_default_to_query_for_get_and_to_body_for_post(): void
    {
        $capturedGet = null;
        $getKernel = $this->fakeKernel(function (Request $req) use (&$capturedGet) { $capturedGet = $req; });
        (new RouteDispatcher($getKernel))->dispatch(
            $this->tool(['input_schema' => ['type' => 'object', 'properties' => []]]),
            ['extra' => 'hello'],
        );
        self::assertSame('hello', $capturedGet->query('extra'));

        $capturedPost = null;
        $postKernel = $this->fakeKernel(function (Request $req) use (&$capturedPost) { $capturedPost = $req; });
        (new RouteDispatcher($postKernel))->dispatch(
            $this->tool([
                'method' => 'POST',
                'path'   => '/api/v3/orders',
                'input_schema' => ['type' => 'object', 'properties' => []],
            ]),
            ['extra' => 'hello'],
        );
        self::assertSame('hello', $capturedPost->json('extra'));
    }
}