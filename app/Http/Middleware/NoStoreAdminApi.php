<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every admin-api JSON response must be fresh, never cached.
 *
 * The admin console's own HTML shell got this fix in 2.42.2, after a stale
 * cached copy left the console showing an old, already-fixed bug. The JSON
 * endpoints underneath every admin screen never got the same treatment —
 * meaning a host-level cache (shared hosting commonly caches GET requests by
 * default, including API responses, unless told not to) could serve a stale
 * "module off" or "settings unsaved" response after a save had already
 * succeeded server-side. The save worked; the next read just showed the
 * cache's memory of the previous one.
 *
 * Applied to the whole admin-api route group rather than one controller at a
 * time, so no future admin screen can quietly reintroduce this.
 */
class NoStoreAdminApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
