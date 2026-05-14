<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator;

final class OpenApiOverlay
{
    /** @var array<string, array<string, array<string, mixed>>>  Index: path => method => operation */
    private array $byPath = [];

    /** @var array<int, string>  Sorted list of paths for suffix matching, longest first */
    private array $pathsByLength = [];

    /** @var array<string, mixed> */
    private array $spec;

    private function __construct(array $spec)
    {
        $this->spec = $spec;
        foreach (($spec['paths'] ?? []) as $path => $methods) {
            if (! is_array($methods)) {
                continue;
            }
            $this->byPath[$path] = [];
            foreach ($methods as $m => $op) {
                if (! is_array($op)) {
                    continue;
                }
                $this->byPath[$path][strtolower((string) $m)] = $op;
            }
        }
        $this->pathsByLength = array_keys($this->byPath);
        usort($this->pathsByLength, static fn ($a, $b) => strlen($b) <=> strlen($a));
    }

    public static function loadOrNull(?string $path): ?self
    {
        if (! $path || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($data)) {
            return null;
        }

        return new self($data);
    }

    public function spec(): array
    {
        return $this->spec;
    }

    public function components(): array
    {
        return $this->spec['components'] ?? [];
    }

    public function servers(): array
    {
        return $this->spec['servers'] ?? [];
    }

    /**
     * Match a Laravel route URI to an overlay path operation.
     *
     * Strategy:
     *   - Normalize Laravel placeholders `{shop}` and overlay placeholders `{shop-slug}` to `*`
     *   - Try exact normalized match, then suffix match (longest first)
     */
    public function findOperation(string $routeUri, string $method): ?array
    {
        $method = strtolower($method);
        $needle = $this->normalize($routeUri);
        foreach ($this->pathsByLength as $candidate) {
            $haystack = $this->normalize($candidate);
            if ($haystack === $needle) {
                return $this->byPath[$candidate][$method] ?? null;
            }
        }
        foreach ($this->pathsByLength as $candidate) {
            $haystack = $this->normalize($candidate);
            // suffix or substring match
            if ($haystack !== '' && str_ends_with('/' . ltrim($needle, '/'), '/' . ltrim($haystack, '/'))) {
                return $this->byPath[$candidate][$method] ?? null;
            }
        }

        return null;
    }

    private function normalize(string $path): string
    {
        $p = '/' . ltrim($path, '/');
        // Replace any `{...}` placeholder with `*`
        return preg_replace('/\{[^}]+\}/', '*', $p) ?? $p;
    }
}
