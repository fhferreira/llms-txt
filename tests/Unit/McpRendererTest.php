<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Tests\Unit;

use Fhferreira\LlmsTxt\Generator\McpRenderer;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpRendererTest extends TestCase
{
    private const META = [
        'title'       => 'Demo API',
        'description' => 'For tests.',
        'version'     => '1.0',
        'base_url'    => 'https://api.example.test/v1',
        'auth'        => [
            'scheme' => 'bearer',
            'header' => 'Authorization',
            'note'   => 'Send a Bearer token.',
        ],
    ];

    private function endpoint(array $overrides = []): array
    {
        return array_replace([
            'method'        => 'GET',
            'methods'       => ['GET'],
            'path'          => '/api/v3/{storename}/orders',
            'name'          => 'api.v3.orders.list',
            'action'        => 'App\\Http\\Controllers\\Api\\V3\\OrderAPIController@orders',
            'controller'    => 'OrderAPI',
            'tag'           => 'Orders',
            'summary'       => 'List orders',
            'description'   => null,
            'parameters'    => [
                ['name' => 'storename', 'in' => 'path',  'required' => true,  'type' => 'string', 'description' => 'Store slug', 'example' => null],
                ['name' => 'limit',     'in' => 'query', 'required' => false, 'type' => 'integer', 'description' => '', 'example' => null, 'rules' => ['integer', 'min:1', 'max:250']],
            ],
            'requestBody'   => null,
            'responses'     => [],
            'auth'          => null,
            'rateLimit'     => 'Rate limit: 180,1',
            'middleware'    => [],
            'docUrl'        => null,
            'llmsAttribute' => null,
        ], $overrides);
    }

    private function decode(string $json): array
    {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function output_has_spec_envelope_and_api_block(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([$this->endpoint()]));
        $doc  = $this->decode($json);

        self::assertSame(1, $doc['spec_version']);
        self::assertArrayHasKey('generated_at', $doc);
        self::assertSame('https://api.example.test/v1', $doc['api']['base_url']);
        self::assertSame('bearer', $doc['api']['auth']['type']);
        self::assertSame('Authorization', $doc['api']['auth']['header']);
    }

    #[Test]
    public function handler_class_is_never_emitted(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([$this->endpoint()]));

        self::assertStringNotContainsString('OrderAPIController', $json);
        self::assertStringNotContainsString('App\\\\Http\\\\Controllers', $json);
        self::assertStringNotContainsString('"action"', $json);
        self::assertStringNotContainsString('"handler"', $json);
    }

    #[Test]
    public function tool_name_derives_from_route_name_with_api_prefix_stripped(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([$this->endpoint()]));
        $doc  = $this->decode($json);

        self::assertSame('v3_orders_list', $doc['tools'][0]['name']);
    }

    #[Test]
    public function tool_name_falls_back_to_method_and_path_tokens(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([
            $this->endpoint(['name' => null, 'path' => '/api/v3/{storename}/orders/count', 'method' => 'GET']),
        ]));
        $doc  = $this->decode($json);

        self::assertSame('v3_orders_count', $doc['tools'][0]['name']);
    }

    #[Test]
    public function llms_attribute_overrides_name_and_description(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([
            $this->endpoint([
                'llmsAttribute' => ['name' => 'orders_list', 'description' => 'List paginated orders.', 'scope' => 'read'],
            ]),
        ]));
        $doc  = $this->decode($json);

        self::assertSame('orders_list', $doc['tools'][0]['name']);
        self::assertSame('List paginated orders.', $doc['tools'][0]['description']);
        self::assertSame('read', $doc['tools'][0]['scope']);
    }

    #[Test]
    public function input_schema_uses_rules_to_json_schema_for_query_params(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([$this->endpoint()]));
        $doc  = $this->decode($json);

        $props = $doc['tools'][0]['input_schema']['properties'];
        self::assertSame('string', $props['storename']['type']);
        self::assertSame('path',   $props['storename']['in']);
        self::assertSame('integer', $props['limit']['type']);
        self::assertSame(1,   $props['limit']['minimum']);
        self::assertSame(250, $props['limit']['maximum']);
        self::assertSame('query', $props['limit']['in']);
        self::assertContains('storename', $doc['tools'][0]['input_schema']['required']);
        self::assertNotContains('limit', $doc['tools'][0]['input_schema']['required'] ?? []);
    }

    #[Test]
    public function rate_limit_string_is_parsed_to_structured_object(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([$this->endpoint()]));
        $doc  = $this->decode($json);

        self::assertSame(180, $doc['tools'][0]['rate_limit']['requests']);
        self::assertSame(1,   $doc['tools'][0]['rate_limit']['per_minutes']);
    }

    #[Test]
    public function description_falls_back_to_generated_phrase_when_summary_and_attr_missing(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([
            $this->endpoint(['summary' => null, 'llmsAttribute' => null, 'method' => 'DELETE', 'path' => '/api/v3/{storename}/orders/{orderId}']),
        ]));
        $doc  = $this->decode($json);

        self::assertSame('Delete orderId', $this->normalize($doc['tools'][0]['description']));
    }

    #[Test]
    public function endpoints_array_mirrors_full_detail(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([
            $this->endpoint([
                'responses' => [
                    '200' => ['description' => 'OK', 'mediaType' => 'application/json', 'schema' => null, 'example' => ['id' => 1]],
                ],
            ]),
        ]));
        $doc  = $this->decode($json);

        self::assertSame('GET', $doc['endpoints'][0]['method']);
        self::assertSame('api.v3.orders.list', $doc['endpoints'][0]['operation']);
        self::assertSame('OK', $doc['endpoints'][0]['responses']['200']['description']);
        self::assertSame(1, $doc['endpoints'][0]['responses']['200']['example']['id']);
    }

    #[Test]
    public function dot_path_parameter_nests_into_object(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([
            $this->endpoint([
                'method' => 'POST',
                'parameters' => [
                    ['name' => 'storename', 'in' => 'path', 'required' => true, 'type' => 'string', 'description' => null, 'example' => null],
                    ['name' => 'image.src', 'in' => 'body', 'required' => false, 'type' => 'string', 'description' => null, 'example' => null, 'rules' => ['string', 'max:255']],
                    ['name' => 'image.position', 'in' => 'body', 'required' => false, 'type' => 'integer', 'description' => null, 'example' => null, 'rules' => ['integer', 'gt:0']],
                ],
            ]),
        ]));
        $doc  = $this->decode($json);
        $props = $doc['tools'][0]['input_schema']['properties'];

        self::assertSame('object', $props['image']['type']);
        self::assertSame('string', $props['image']['properties']['src']['type']);
        self::assertSame(255, $props['image']['properties']['src']['maxLength']);
        self::assertSame('integer', $props['image']['properties']['position']['type']);
        self::assertSame(0, $props['image']['properties']['position']['exclusiveMinimum'] ?? null,
            'gt:0 should translate to exclusiveMinimum:0 on integer');
        self::assertFalse(isset($props['image.src']),  'dot-path key must not appear flat');
    }

    #[Test]
    public function wildcard_segment_produces_array_of_objects(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([
            $this->endpoint([
                'method' => 'POST',
                'parameters' => [
                    ['name' => 'storename', 'in' => 'path', 'required' => true, 'type' => 'string', 'description' => null, 'example' => null],
                    ['name' => 'line_items.*.variant_id', 'in' => 'body', 'required' => true, 'type' => 'number', 'description' => null, 'example' => null, 'rules' => ['required', 'numeric']],
                    ['name' => 'line_items.*.quantity',   'in' => 'body', 'required' => true, 'type' => 'number', 'description' => null, 'example' => null, 'rules' => ['required', 'numeric']],
                ],
            ]),
        ]));
        $doc  = $this->decode($json);
        $line = $doc['tools'][0]['input_schema']['properties']['line_items'];

        self::assertSame('array',  $line['type']);
        self::assertSame('object', $line['items']['type']);
        self::assertSame('number', $line['items']['properties']['variant_id']['type']);
        self::assertSame('number', $line['items']['properties']['quantity']['type']);
        self::assertEqualsCanonicalizing(['variant_id', 'quantity'], $line['items']['required']);
    }

    #[Test]
    public function leaf_wildcard_produces_array_of_primitives(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([
            $this->endpoint([
                'method' => 'POST',
                'parameters' => [
                    ['name' => 'storename', 'in' => 'path', 'required' => true, 'type' => 'string', 'description' => null, 'example' => null],
                    ['name' => 'tags.*', 'in' => 'body', 'required' => false, 'type' => 'string', 'description' => null, 'example' => null, 'rules' => ['string', 'min:1']],
                ],
            ]),
        ]));
        $doc  = $this->decode($json);
        $tags = $doc['tools'][0]['input_schema']['properties']['tags'];

        self::assertSame('array', $tags['type']);
        self::assertSame('string', $tags['items']['type']);
        self::assertSame(1, $tags['items']['minLength']);
    }

    #[Test]
    public function deep_nesting_three_levels(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([
            $this->endpoint([
                'method' => 'POST',
                'parameters' => [
                    ['name' => 'storename', 'in' => 'path', 'required' => true, 'type' => 'string', 'description' => null, 'example' => null],
                    ['name' => 'cartLineItems.variant.product', 'in' => 'body', 'required' => true, 'type' => 'string', 'description' => null, 'example' => null, 'rules' => ['required', 'string']],
                ],
            ]),
        ]));
        $doc  = $this->decode($json);
        $deep = $doc['tools'][0]['input_schema']['properties']['cartLineItems']['properties']['variant']['properties']['product'];

        self::assertSame('string', $deep['type']);
    }

    #[Test]
    public function flat_parameters_still_render_unchanged(): void
    {
        $json = (new McpRenderer(self::META))->render(new Collection([
            $this->endpoint(),
        ]));
        $doc  = $this->decode($json);
        $props = $doc['tools'][0]['input_schema']['properties'];

        self::assertSame('string',  $props['storename']['type']);
        self::assertSame('integer', $props['limit']['type']);
        self::assertSame(1,   $props['limit']['minimum']);
        self::assertSame(250, $props['limit']['maximum']);
        self::assertSame(['storename'], $doc['tools'][0]['input_schema']['required']);
    }

    private function normalize(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}