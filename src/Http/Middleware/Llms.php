<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class Llms
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
