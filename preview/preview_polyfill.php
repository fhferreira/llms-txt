<?php

declare(strict_types=1);

/**
 * Tiny Illuminate\Support\Collection polyfill — covers ONLY the methods used
 * by the renderers (count, pluck, unique, groupBy, sortKeys, isEmpty, map).
 *
 * Used only for the standalone preview.php run; the real package consumes the
 * real Illuminate Collection at runtime.
 */

namespace Illuminate\Support {

    if (! class_exists(Collection::class)) {
        class Collection implements \IteratorAggregate, \Countable
        {
            public function __construct(private array $items = []) {}

            public function isEmpty(): bool { return $this->items === []; }
            public function count(): int    { return count($this->items); }
            public function all(): array    { return $this->items; }
            public function toArray(): array { return $this->items; }
            public function values(): self  { return new self(array_values($this->items)); }

            public function map(callable $cb): self
            {
                return new self(array_map($cb, $this->items));
            }

            public function pluck(string $key): self
            {
                $out = [];
                foreach ($this->items as $row) {
                    $out[] = is_array($row) ? ($row[$key] ?? null) : ($row->$key ?? null);
                }

                return new self($out);
            }

            public function unique(): self
            {
                return new self(array_values(array_unique($this->items, SORT_REGULAR)));
            }

            public function groupBy(callable|string $by): self
            {
                $cb = is_callable($by) ? $by : static fn ($row) => is_array($row) ? ($row[$by] ?? null) : ($row->$by ?? null);
                $groups = [];
                foreach ($this->items as $row) {
                    $k = $cb($row);
                    $groups[(string) ($k ?? '')][] = $row;
                }
                foreach ($groups as $k => $rows) {
                    $groups[$k] = new self($rows);
                }

                return new self($groups);
            }

            public function sortKeys(): self
            {
                $arr = $this->items;
                ksort($arr, SORT_NATURAL | SORT_FLAG_CASE);

                return new self($arr);
            }

            public function get(string|int $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }

            public function keyBy(callable|string $by): self
            {
                $cb = is_callable($by) ? $by : static fn ($row) => is_array($row) ? ($row[$by] ?? null) : ($row->$by ?? null);
                $out = [];
                foreach ($this->items as $row) {
                    $out[(string) $cb($row)] = $row;
                }

                return new self($out);
            }

            public function getIterator(): \Iterator
            {
                return new \ArrayIterator($this->items);
            }
        }
    }
}

namespace {
    if (! function_exists('collect')) {
        function collect(array $items = []): \Illuminate\Support\Collection
        {
            return new \Illuminate\Support\Collection($items);
        }
    }
}
