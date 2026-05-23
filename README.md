# fhferreira/llms-txt

Generate [llms.txt](https://llmstxt.org/) and `llms-full.txt` for Laravel routes — so LLM tooling and agents can integrate with your API without having to scrape your docs site.

## Install

### From this GitHub repo (recommended)

Add the VCS repository and require the package in your host `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:fhferreira/llms-txt.git" }
    ],
    "require": {
        "fhferreira/llms-txt": "dev-main"
    }
}
```

```bash
composer update fhferreira/llms-txt
php artisan vendor:publish --tag=llms-txt-config   # optional
```

The service provider auto-discovers (`extra.laravel.providers` in `composer.json`).

### As an in-repo path package (alternative)

Drop the package source at `packages/fhferreira/llms-txt/` and point Composer at it locally:

```json
{
    "repositories": [
        { "type": "path", "url": "packages/fhferreira/llms-txt" }
    ],
    "require": {
        "fhferreira/llms-txt": "*"
    }
}
```

## Usage

Tag any group of routes with the `llms` middleware. The middleware is a no-op marker — it only signals "include this route in the generated llms.txt".

```php
Route::middleware('llms')->prefix('api/v3/{shop-slug}')->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    // ...
});
```

Then generate:

```bash
php artisan llms:generate
```

Outputs (defaults):

- `public/llms.txt`       — short curated index
- `public/llms-full.txt`  — per-endpoint reference (params, request body, response examples)
- `public/llms-mcp.json`  — MCP tool catalog + structured endpoint detail (machine-readable)

Controller FQCNs are **never** written to any of these files. Only request/response-shaped data is public.

## Commands

### `php artisan llms:generate`

Walks every route whose middleware chain includes the marker, runs the inspectors, and writes `llms.txt` + `llms-full.txt`.

| Option | Description |
|---|---|
| `--out=…` | Override the llms.txt output path |
| `--out-full=…` | Override the llms-full.txt output path |
| `--out-mcp=…` | Override the llms-mcp.json output path |
| `--overlay=…` | Path to an OpenAPI 3 JSON to merge in (overrides config) |
| `--dry-run` | Print to stdout instead of writing |

#### `llms-mcp.json` shape

```json
{
  "spec_version": 1,
  "generated_at": "2026-05-23T12:00:00Z",
  "api":  { "base_url": "https://api.example.com", "auth": {"type": "bearer", "header": "Authorization"} },
  "tools": [
    {
      "name":         "v3_orders_list",
      "description":  "List orders",
      "method":       "GET",
      "path":         "/api/v3/{storename}/orders",
      "scope":        "read",
      "rate_limit":   {"requests": 180, "per_minutes": 1},
      "input_schema": {
        "type": "object",
        "properties": {
          "storename": {"type": "string",  "in": "path"},
          "limit":     {"type": "integer", "in": "query", "minimum": 1, "maximum": 250}
        },
        "required": ["storename"],
        "additionalProperties": false
      }
    }
  ],
  "endpoints": [ /* full request/response detail per endpoint */ ]
}
```

Tool `name` resolution order:
1. `#[Llms(name: '…')]` on the controller action
2. Route name (`api.v3.orders.list` → `v3_orders_list`)
3. METHOD + path tokens (`GET /api/v3/{storename}/orders/count` → `v3_orders_count`)

Tool `description` resolution order:
1. `#[Llms(description: '…')]`
2. First line of the controller method's PHPDoc
3. Auto-generated verb + last noun (`Delete order`)

#### `#[Llms]` attribute

Use the attribute as an opt-in escape hatch when route name / docblock aren't enough:

```php
use Fhferreira\LlmsTxt\Attributes\Llms;

class OrderAPIController
{
    #[Llms(name: 'orders_list', description: 'List paginated orders for a store', scope: 'read')]
    public function orders(ListOrdersRequest $request) { /* ... */ }
}
```

### `php artisan llms:cleanup-local`

If you developed against this package as a path repository (e.g. `packages/fhferreira/llms-txt/`) and have since switched to a normal `composer require`, this command removes the leftover in-repo copy so the autoloader uses only the `vendor/` version.

| Option | Description |
|---|---|
| `--path=…` | Path to the in-repo copy (default: `packages/fhferreira/llms-txt`) |
| `--force` | Skip the interactive confirmation prompt |

Safety:
- Refuses to delete if this command is itself loaded from the target path.
- Walks up the parent directories and removes them only if empty (so `packages/other/...` won't be touched).
- Idempotent: no-op if the target doesn't exist.

## Configuration

See `config/llms-txt.php` after publishing. Highlights:

| Key | What |
|---|---|
| `middleware_marker` | Alias used to tag routes (default: `llms`) |
| `api.*` | Title, base URL, auth note shown at top of generated files |
| `output.llms_txt` / `output.llms_full_txt` / `output.llms_mcp_json` | Destination paths |
| `openapi_overlay` | Optional path to an existing OpenAPI 3 JSON. When set, response/request examples and richer descriptions are merged in by path match. |
| `grouping` | `tag` (from overlay), `controller`, or `prefix` |

## How endpoint detail is inferred

For each route in the `llms` group, the generator runs a chain of inspectors:

1. **Controller PHPDoc** — first paragraph as summary.
2. **Form Request** — if the controller method type-hints a `FormRequest` subclass, `rules()` is reflected to emit a field list.
3. **API Resource** — if the controller method returns a `JsonResource` subclass, `toArray()` is statically inspected for the response shape.
4. **Middleware chain** — `auth:*` and `throttle:*` produce auth/rate-limit notes per endpoint.
5. **OpenAPI overlay** — when a matching path exists in the configured overlay file, request body example, response examples per status code, and parameter descriptions are merged.

The inspectors fail soft — a route missing a Form Request, Resource, or overlay match still produces a usable entry with just method + path + summary.
