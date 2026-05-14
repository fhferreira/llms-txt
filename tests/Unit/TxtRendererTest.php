<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Tests\Unit;

use Fhferreira\LlmsTxt\Generator\TxtRenderer;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TxtRendererTest extends TestCase
{
    private const META = [
        'title'       => 'Demo API',
        'description' => 'Just for tests.',
        'version'     => '1.2',
        'base_url'    => 'https://api.example.test/v1',
        'auth'        => ['note' => 'Use a Bearer token.'],
        'rate_limit_note' => '60 req/min.',
    ];

    private function endpoint(array $overrides = []): array
    {
        return array_replace([
            'method'     => 'GET',
            'path'       => '/orders',
            'tag'        => 'Orders',
            'summary'    => 'List orders',
            'docUrl'     => null,
            'parameters' => [],
            'requestBody'=> null,
            'responses'  => [],
            'auth'       => null,
            'rateLimit'  => null,
            'middleware' => [],
            'action'     => null,
            'name'       => null,
            'controller' => null,
            'description'=> null,
            'methods'    => ['GET'],
        ], $overrides);
    }

    #[Test]
    public function renders_title_overview_and_base_url(): void
    {
        $out = (new TxtRenderer(self::META))->render(new Collection([$this->endpoint()]));

        self::assertStringContainsString('# Demo API', $out);
        self::assertStringContainsString('> Just for tests.', $out);
        self::assertStringContainsString('- **Version**: 1.2', $out);
        self::assertStringContainsString('- **Base URL**: `https://api.example.test/v1`', $out);
        self::assertStringContainsString('## Authentication', $out);
        self::assertStringContainsString('Use a Bearer token.', $out);
        self::assertStringContainsString('## Rate limits', $out);
        self::assertStringContainsString('60 req/min.', $out);
    }

    #[Test]
    public function endpoints_are_grouped_by_tag_alphabetically(): void
    {
        $out = (new TxtRenderer(self::META))->render(new Collection([
            $this->endpoint(['tag' => 'Webhook', 'path' => '/webhooks', 'summary' => 'List webhooks']),
            $this->endpoint(['tag' => 'Orders',  'path' => '/orders',   'summary' => 'List orders']),
            $this->endpoint(['tag' => 'Customers', 'path' => '/customers', 'summary' => 'List customers']),
        ]));

        $orderHeading = strpos($out, '### Orders');
        $custHeading  = strpos($out, '### Customers');
        $webHeading   = strpos($out, '### Webhook');

        self::assertNotFalse($custHeading);
        self::assertNotFalse($orderHeading);
        self::assertNotFalse($webHeading);
        self::assertLessThan($orderHeading, $custHeading, 'Customers should come before Orders');
        self::assertLessThan($webHeading,   $orderHeading, 'Orders should come before Webhook');
    }

    #[Test]
    public function endpoint_line_includes_method_path_and_summary(): void
    {
        $out = (new TxtRenderer(self::META))->render(new Collection([
            $this->endpoint(['method' => 'POST', 'path' => '/orders', 'summary' => 'Create order']),
        ]));

        self::assertStringContainsString('- `POST /orders` — Create order', $out);
    }

    #[Test]
    public function doc_url_renders_as_inline_link_when_present(): void
    {
        $out = (new TxtRenderer(self::META))->render(new Collection([
            $this->endpoint(['docUrl' => 'https://docs.example.test/orders']),
        ]));

        self::assertStringContainsString('([docs](https://docs.example.test/orders))', $out);
    }

    #[Test]
    public function untagged_endpoints_fall_back_to_other_section(): void
    {
        $out = (new TxtRenderer(self::META))->render(new Collection([
            $this->endpoint(['tag' => null]),
        ]));

        self::assertStringContainsString('### Other', $out);
    }

    #[Test]
    public function output_ends_with_single_trailing_newline(): void
    {
        $out = (new TxtRenderer(self::META))->render(new Collection([$this->endpoint()]));

        self::assertSame("\n", substr($out, -1));
        self::assertNotSame("\n", substr($out, -2, 1), 'output should not end with double newline');
    }
}
