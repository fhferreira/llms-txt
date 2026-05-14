<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Tests\Unit;

use Fhferreira\LlmsTxt\Generator\FullTxtRenderer;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FullTxtRendererTest extends TestCase
{
    private const META = [
        'title'       => 'Demo API',
        'description' => 'For tests.',
        'base_url'    => 'https://api.example.test/v1',
        'auth'        => ['note' => 'Bearer token in header.'],
    ];

    private function endpoint(array $overrides = []): array
    {
        return array_replace([
            'method'      => 'GET',
            'path'        => '/orders/{id}',
            'tag'         => 'Orders',
            'summary'     => 'Get order',
            'description' => null,
            'parameters'  => [],
            'requestBody' => null,
            'responses'   => [],
            'auth'        => null,
            'rateLimit'   => null,
            'middleware'  => [],
            'action'      => null,
            'name'        => null,
            'controller'  => null,
            'docUrl'      => null,
            'methods'     => ['GET'],
        ], $overrides);
    }

    #[Test]
    public function header_includes_title_base_url_and_auth_note(): void
    {
        $out = (new FullTxtRenderer(self::META))->render(new Collection([$this->endpoint()]));

        self::assertStringContainsString('# Demo API — Full Reference', $out);
        self::assertStringContainsString('> For tests.', $out);
        self::assertStringContainsString('**Base URL**: `https://api.example.test/v1`', $out);
        self::assertStringContainsString('**Authentication**: Bearer token in header.', $out);
    }

    #[Test]
    public function endpoint_heading_format_is_backtick_method_path(): void
    {
        $out = (new FullTxtRenderer(self::META))->render(new Collection([
            $this->endpoint(['method' => 'POST', 'path' => '/orders', 'summary' => 'Create']),
        ]));

        self::assertStringContainsString('### `POST /orders` — Create', $out);
    }

    #[Test]
    public function parameters_are_grouped_by_in_and_emit_markdown_tables(): void
    {
        $out = (new FullTxtRenderer(self::META))->render(new Collection([
            $this->endpoint([
                'parameters' => [
                    ['name' => 'id',      'in' => 'path',  'required' => true,  'type' => 'integer', 'description' => 'Order id', 'example' => null],
                    ['name' => 'expand',  'in' => 'query', 'required' => false, 'type' => 'string',  'description' => 'Include relations', 'example' => null],
                ],
            ]),
        ]));

        self::assertStringContainsString('**Path parameters**', $out);
        self::assertStringContainsString('**Query parameters**', $out);
        self::assertStringContainsString('| `id` | integer | **required** | Order id |', $out);
        self::assertStringContainsString('| `expand` | string |  | Include relations |', $out);
    }

    #[Test]
    public function request_body_example_emits_fenced_json_block(): void
    {
        $out = (new FullTxtRenderer(self::META))->render(new Collection([
            $this->endpoint([
                'method' => 'POST',
                'path'   => '/orders',
                'requestBody' => [
                    'mediaType' => 'application/json',
                    'schema'    => null,
                    'example'   => ['customer_id' => 42, 'items' => [['sku' => 'X']]],
                ],
            ]),
        ]));

        self::assertStringContainsString('**Request body** (`application/json`)', $out);
        self::assertStringContainsString('```json', $out);
        self::assertStringContainsString('"customer_id": 42', $out);
        self::assertStringContainsString('"sku": "X"', $out);
    }

    #[Test]
    public function responses_render_with_status_codes_and_examples(): void
    {
        $out = (new FullTxtRenderer(self::META))->render(new Collection([
            $this->endpoint([
                'responses' => [
                    '200' => ['description' => 'OK',        'mediaType' => 'application/json', 'schema' => null, 'example' => ['id' => 1]],
                    '404' => ['description' => 'Not Found', 'mediaType' => 'application/json', 'schema' => null, 'example' => null],
                ],
            ]),
        ]));

        self::assertStringContainsString('**Responses**', $out);
        self::assertStringContainsString('- **200** (`application/json`) — OK', $out);
        self::assertStringContainsString('- **404** (`application/json`) — Not Found', $out);
        self::assertStringContainsString('"id": 1', $out);
    }

    #[Test]
    public function empty_response_code_keys_are_skipped(): void
    {
        $out = (new FullTxtRenderer(self::META))->render(new Collection([
            $this->endpoint([
                'responses' => [
                    ''    => ['description' => 'should not appear', 'mediaType' => null, 'schema' => null, 'example' => null],
                    '200' => ['description' => 'OK',                'mediaType' => null, 'schema' => null, 'example' => null],
                ],
            ]),
        ]));

        self::assertStringNotContainsString('should not appear', $out);
        self::assertStringContainsString('- **200**', $out);
    }

    #[Test]
    public function doc_url_renders_in_endpoint_metadata(): void
    {
        $out = (new FullTxtRenderer(self::META))->render(new Collection([
            $this->endpoint(['docUrl' => 'https://docs.example.test/orders/get']),
        ]));

        self::assertStringContainsString('**Docs**: [https://docs.example.test/orders/get](https://docs.example.test/orders/get)', $out);
    }
}
