<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Attributes;

use Attribute;

/**
 * Per-action overrides surfaced in the generated MCP tool catalog.
 *
 * Example:
 *   #[Llms(name: 'orders_list', description: 'List orders for a store', scope: 'read')]
 *   public function orders(Request $r) { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Llms
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $scope = null,
    ) {}
}