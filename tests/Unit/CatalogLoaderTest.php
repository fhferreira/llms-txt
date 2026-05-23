<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Tests\Unit;

use Fhferreira\LlmsTxt\Mcp\CatalogLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CatalogLoaderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'llms-mcp-') . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmp)) {
            @unlink($this->tmp);
        }
    }

    #[Test]
    public function loads_valid_catalog_into_normalized_shape(): void
    {
        file_put_contents($this->tmp, json_encode([
            'spec_version' => 1,
            'api'          => ['title' => 'Demo'],
            'tools'        => [['name' => 'a'], ['name' => 'b']],
            'endpoints'    => [['method' => 'GET']],
        ]));

        $doc = CatalogLoader::load($this->tmp);

        self::assertSame(1, $doc['spec_version']);
        self::assertSame('Demo', $doc['api']['title']);
        self::assertCount(2, $doc['tools']);
        self::assertCount(1, $doc['endpoints']);
    }

    #[Test]
    public function throws_when_file_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('llms-mcp.json not found');

        CatalogLoader::load($this->tmp . '.does-not-exist');
    }

    #[Test]
    public function throws_when_tools_array_missing(): void
    {
        file_put_contents($this->tmp, json_encode(['spec_version' => 1]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing a `tools` array');

        CatalogLoader::load($this->tmp);
    }
}