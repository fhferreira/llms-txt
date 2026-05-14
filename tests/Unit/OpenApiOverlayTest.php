<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Tests\Unit;

use Fhferreira\LlmsTxt\Generator\OpenApiOverlay;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpenApiOverlayTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/llms-txt-overlay-tests-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function write(string $name, array $spec): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, json_encode($spec));

        return $path;
    }

    #[Test]
    public function returns_null_for_missing_file(): void
    {
        self::assertNull(OpenApiOverlay::loadOrNull($this->tmpDir . '/does-not-exist.json'));
    }

    #[Test]
    public function returns_null_for_invalid_json(): void
    {
        $path = $this->tmpDir . '/broken.json';
        file_put_contents($path, '{ not valid json ');

        self::assertNull(OpenApiOverlay::loadOrNull($path));
    }

    #[Test]
    public function returns_null_for_empty_file(): void
    {
        $path = $this->tmpDir . '/empty.json';
        file_put_contents($path, '');

        self::assertNull(OpenApiOverlay::loadOrNull($path));
    }

    #[Test]
    public function exact_path_match_returns_operation(): void
    {
        $overlay = OpenApiOverlay::loadOrNull($this->write('s.json', [
            'paths' => [
                '/orders' => ['get' => ['summary' => 'List orders']],
            ],
        ]));

        $op = $overlay->findOperation('/orders', 'GET');

        self::assertNotNull($op);
        self::assertSame('List orders', $op['summary']);
    }

    #[Test]
    public function method_match_is_case_insensitive(): void
    {
        $overlay = OpenApiOverlay::loadOrNull($this->write('s.json', [
            'paths' => [
                '/orders' => ['post' => ['summary' => 'Create order']],
            ],
        ]));

        self::assertNotNull($overlay->findOperation('/orders', 'post'));
        self::assertNotNull($overlay->findOperation('/orders', 'POST'));
        self::assertNotNull($overlay->findOperation('/orders', 'Post'));
        self::assertNull($overlay->findOperation('/orders', 'GET'));
    }

    #[Test]
    public function placeholders_are_normalized_so_different_names_still_match(): void
    {
        // overlay uses `{shop-slug}`; route uses `{storename}` — they must match
        $overlay = OpenApiOverlay::loadOrNull($this->write('s.json', [
            'paths' => [
                '/{shop-slug}/orders/{order-id}' => ['get' => ['summary' => 'Get order']],
            ],
        ]));

        $op = $overlay->findOperation('/{storename}/orders/{orderId}', 'GET');

        self::assertNotNull($op);
        self::assertSame('Get order', $op['summary']);
    }

    #[Test]
    public function suffix_match_finds_overlay_path_when_route_has_extra_prefix(): void
    {
        // overlay path: /{shop-slug}/orders
        // route URI:    /api/v3/{storename}/orders
        $overlay = OpenApiOverlay::loadOrNull($this->write('s.json', [
            'paths' => [
                '/{shop-slug}/orders' => ['get' => ['summary' => 'List orders']],
            ],
        ]));

        $op = $overlay->findOperation('/api/v3/{storename}/orders', 'GET');

        self::assertNotNull($op);
        self::assertSame('List orders', $op['summary']);
    }

    #[Test]
    public function longest_matching_suffix_wins(): void
    {
        // both /{slug}/orders and /orders are present — the more specific one should match
        $overlay = OpenApiOverlay::loadOrNull($this->write('s.json', [
            'paths' => [
                '/orders'           => ['get' => ['summary' => 'short']],
                '/{slug}/orders'    => ['get' => ['summary' => 'long']],
            ],
        ]));

        $op = $overlay->findOperation('/api/v3/{storename}/orders', 'GET');

        self::assertNotNull($op);
        self::assertSame('long', $op['summary']);
    }

    #[Test]
    public function returns_null_when_no_path_matches(): void
    {
        $overlay = OpenApiOverlay::loadOrNull($this->write('s.json', [
            'paths' => [
                '/orders' => ['get' => ['summary' => 'List']],
            ],
        ]));

        self::assertNull($overlay->findOperation('/customers', 'GET'));
    }

    #[Test]
    public function spec_method_returns_loaded_spec_intact(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info'    => ['title' => 'API', 'version' => '1.0'],
            'paths'   => ['/x' => ['get' => ['summary' => 's']]],
        ];
        $overlay = OpenApiOverlay::loadOrNull($this->write('s.json', $spec));

        self::assertSame($spec['info'], $overlay->spec()['info']);
        self::assertSame($spec['openapi'], $overlay->spec()['openapi']);
    }
}
