<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Middleware marker
    |--------------------------------------------------------------------------
    |
    | Routes whose middleware chain contains this alias (or class FQCN) are
    | included in the generated llms.txt and llms-full.txt files.
    |
    */
    'middleware_marker' => 'llms',

    /*
    |--------------------------------------------------------------------------
    | API meta
    |--------------------------------------------------------------------------
    |
    | Surfaced in the header of llms.txt / llms-full.txt.
    |
    */
    'api' => [
        'title'       => env('LLMS_TXT_TITLE', config('app.name', 'API') . ' — LLM Guide'),
        'base_url'    => env('LLMS_TXT_BASE_URL', config('app.url')),
        'version'     => env('LLMS_TXT_VERSION', '1.0'),
        'description' => env(
            'LLMS_TXT_DESCRIPTION',
            'Public API. Each request must include a Bearer token. Endpoints are scoped per tenant via the URL.'
        ),
        'auth' => [
            'scheme' => 'bearer',
            'header' => 'Authorization',
            'note'   => 'Send `Authorization: Bearer <token>` on every request.',
        ],
        'rate_limit_note' => 'Default throttle applies. Inspect the `X-RateLimit-*` response headers.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    |
    | Where the generated files are written. Paths are absolute or resolved
    | from base_path(). The well-known location is the host's public root
    | so consumers can fetch `https://your-app/llms.txt`.
    |
    */
    'output' => [
        'llms_txt'       => public_path('llms.txt'),
        'llms_full_txt'  => public_path('llms-full.txt'),
        'llms_mcp_json'  => public_path('llms-mcp.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAPI overlay (optional)
    |--------------------------------------------------------------------------
    |
    | If set to an existing JSON file path, the generator merges richer
    | per-endpoint detail (request body example, response examples) from the
    | overlay onto routes whose path matches. Useful when an existing API
    | already has a hand-written or scraped OpenAPI spec.
    |
    | The path-match strategy is suffix-based: a Laravel route URI of
    | `api/v3/{shop-slug}/orders` is matched against any overlay path that
    | ends with `/{shop-slug}/orders`.
    |
    */
    'openapi_overlay' => env('LLMS_TXT_OPENAPI_OVERLAY', storage_path('app/llms-txt/openapi.json')),

    /*
    |--------------------------------------------------------------------------
    | Doc URL prefix (optional)
    |--------------------------------------------------------------------------
    |
    | When the OpenAPI overlay sets a per-operation `x-doc-slug`, the generator
    | resolves it to a full doc URL using (in priority order):
    |   1. `x-doc-url` on the operation (full URL — wins outright)
    |   2. spec-level `x-doc-base-url` + `x-doc-slug`
    |   3. this config value + `x-doc-slug`
    |
    | Set to null to skip generating doc URLs entirely.
    |
    */
    'doc_url_prefix' => env('LLMS_TXT_DOC_URL_PREFIX'),

    /*
    |--------------------------------------------------------------------------
    | Grouping
    |--------------------------------------------------------------------------
    |
    | How to derive the section/category for each endpoint in the output.
    |
    |   'tag'        — first `tags[]` entry from OpenAPI overlay (preferred), else fallback
    |   'controller' — short controller class name without `Controller` suffix
    |   'prefix'     — first URI segment after stripping `api/v{N}/`
    |
    */
    'grouping' => 'tag',
];
