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

## Configuration

See `config/llms-txt.php` after publishing. Highlights:

| Key | What |
|---|---|
| `middleware_marker` | Alias used to tag routes (default: `llms`) |
| `api.*` | Title, base URL, auth note shown at top of generated files |
| `output.llms_txt` / `output.llms_full_txt` | Destination paths |
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
