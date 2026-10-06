<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read the settings maps from the cache once per web request. (Lane SP)
 *
 * The why, the measurements and the invalidation rules are on
 * SettingsService's REQUEST_MEMO_KEYS. This only marks where a request starts
 * and ends, so the memo never outlives the request that filled it: the end is
 * in `finally`, so an exception that escapes the page cannot leave one
 * request's settings in place for whatever runs next in the same process (a
 * test, Octane, a queue worker handling a sync job).
 */
final class SettingsRequestMemo
{
    public function handle(Request $request, Closure $next): Response
    {
        SettingsService::beginRequestMemo();

        try {
            return $next($request);
        } finally {
            SettingsService::endRequestMemo();
        }
    }
}
