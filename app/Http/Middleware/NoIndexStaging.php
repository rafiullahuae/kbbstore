<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps staging out of search results.
 *
 * A staging copy of a live store is the classic duplicate-content accident: it
 * carries the same product names and descriptions as the real site, and Google
 * has been known to index it and rank it against you. One header prevents it.
 *
 * Driven by KBB_NOINDEX so production physically cannot inherit it.
 */
class NoIndexStaging
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (env('KBB_NOINDEX', false)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow', true);
        }

        return $response;
    }
}
