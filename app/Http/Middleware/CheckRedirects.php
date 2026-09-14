<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the incoming path against admin-defined (or auto-created) redirects
 * before the request reaches routing. Has to run early, not as a fallback
 * for unmatched routes — a redirect must take priority even over a route
 * that *would* otherwise match something, since the whole reason a URL is
 * being redirected is that whatever's now at the old path isn't what the
 * admin wants a visitor landing on.
 *
 * Skipped entirely for admin/API/asset paths — a redirect rule is a
 * storefront-URL concept; checking it against every asset request would be
 * pure overhead for something that can never match one anyway.
 */
class CheckRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        $redirect = self::findMatch($request);

        if ($redirect === null) {
            return $next($request);
        }

        self::recordHit($redirect);

        return redirect($redirect->target, $redirect->code);
    }

    /**
     * The matching logic itself, factored out so the exception handler can
     * reuse it directly rather than duplicate it. Needed because this
     * middleware, applied via Laravel 11's middleware groups from a
     * service provider, turned out not to actually run for most real
     * requests — confirmed directly: a redirect on an existing, matched
     * route still returned 200 instead of 301 with that registration
     * approach, both from boot() and from register(). The exception
     * handler catches every 404 regardless of how it was thrown, so that's
     * where the real check now lives; this class stays as where the logic
     * itself is defined and unit-testable, and remains directly usable as
     * real middleware for anyone future who does confirm a working
     * registration path for it.
     */
    public static function findMatch(Request $request): ?Redirect
    {
        if (!$request->isMethod('GET') || self::isExemptPath($request->path())) {
            return null;
        }

        // getPathInfo(), not path() — path() strips the trailing slash,
        // but this site's real URLs deliberately keep one (the same
        // WordPress-matching convention Url::to() documents as U-01), so
        // stripping it here would mean a redirect stored for "/foo/" could
        // never actually match the real incoming request for "/foo/".
        return Redirect::query()->where('source', $request->getPathInfo())->where('enabled', true)->first();
    }

    public static function recordHit(Redirect $redirect): void
    {
        // Best-effort — a failure here must never block the actual
        // redirect from happening.
        try {
            DB::table('redirects')->where('id', $redirect->id)->update([
                'hits' => $redirect->hits + 1,
                'last_hit_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Swallowed deliberately — see comment above.
        }
    }

    private static function isExemptPath(string $path): bool
    {
        foreach (['admin', 'admin-api', 'api', 'build', 'uploads', 'storage'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
