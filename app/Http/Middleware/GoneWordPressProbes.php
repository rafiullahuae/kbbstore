<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AdminPathService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 410 Gone for the WordPress addresses robots keep asking for.     (Lane WJ)
 *
 * The owner: "is there any solution to stop those urls etc?" Store → Analytics
 * was full of "Page not found | K-Beauty Bliss UAE · United States · desktop ·
 * Direct": scanners requesting /wp-json/oembed/1.0/embed, /xmlrpc.php,
 * /wp-login.php and friends. There is no WordPress behind this shop any more,
 * so every one of them fell through to the themed 404 page -- a full render
 * with a session, settings, a trending-products block and the analytics
 * beacon -- for a request no person made.
 *
 * WHAT IT ANSWERS: a path with a WordPress-only segment (wp-admin, wp-includes,
 * wp-json, xmlrpc.php, any wp-*.php such as wp-login.php, wp-cron.php,
 * wp-config.php) anywhere in it -- bots probe /wordpress/wp-admin/ and
 * /blog/wp-login.php as often as the root -- and the home page asked for
 * ?rest_route=, WordPress's other spelling of /wp-json. The answer is 410 and
 * the word "Gone": a crawler drops a 410 for good, where a 404 is retried.
 *
 * WHAT IT NEVER TOUCHES, and each of these is a test:
 *
 *   /wp-content/...   old picture addresses are redirected or served
 *                     (App\Support\LegacyImageRedirect), so the whole tree is
 *                     left to the normal 404 handling, plugins and themes too.
 *   a redirect row    a path with an enabled row on Store → SEO & Meta →
 *                     Redirects & 404s (an imported Search Console 404
 *                     included) goes on to CheckRedirects exactly as before.
 *                     CheckRedirects::lookup() is the same answer that
 *                     middleware gives, so the two cannot disagree.
 *   the admin path    `admin_path` is the owner's to pick and "wp-admin" passes
 *                     AdminPathService's rules. His back office is never Gone.
 *   /ar/...           an Arabic-only redirect row is matched in the locale
 *                     SetLocaleFromPath sets, later than this runs; rather than
 *                     guess, an /ar probe keeps the ordinary 404.
 *   /api, /storage, /build, /uploads, /img-cache, /admin-api: real trees.
 *
 * WHERE IT RUNS, AND WHY THAT IS THE CHEAP PLACE: in the global stack, second
 * only to SettingsRequestMemo (which reads nothing by itself -- it opens the
 * per-request memo and steps aside), registered from AppServiceProvider::boot().
 * That is before ComingSoonGate and CanonicalHost, which read the settings map,
 * before the router, and before the `web` group, so a probe starts no session,
 * reads no setting, renders no view and writes no 404 log row. Its whole cost
 * is the redirect index CheckRedirects already keeps in the cache (no query
 * unless a row claims the path, and then the row wins). Every other request
 * pays one split of its path and a few string compares, and is passed on.
 */
final class GoneWordPressProbes
{
    /** Whole segments that only ever meant WordPress. */
    private const SEGMENTS = ['wp-admin', 'wp-includes', 'wp-json', 'xmlrpc.php'];

    /** First segments that are real trees here, and are never answered. */
    private const PASS = ['wp-content', 'ar', 'api', 'admin-api', 'storage', 'build', 'uploads', 'img-cache'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::isProbe($request)) {
            return $next($request);
        }

        // The owner's own redirect for this address still fires (GET only,
        // as CheckRedirects only ever redirects a GET).
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && CheckRedirects::lookup($request->getPathInfo()) !== null) {
            return $next($request);
        }

        return self::gone();
    }

    /** Is this a WordPress probe? No settings, no database, no session. */
    public static function isProbe(Request $request): bool
    {
        $path = strtolower(trim($request->getPathInfo(), '/'));

        if ($path === '') {
            return $request->query->has('rest_route');
        }

        $segments = explode('/', $path);

        if (in_array($segments[0], self::PASS, true)) {
            return false;
        }

        $probe = false;

        foreach ($segments as $segment) {
            if (in_array($segment, self::SEGMENTS, true) || preg_match('/^wp-[a-z0-9_-]+\.php/', $segment) === 1) {
                $probe = true;
                break;
            }
        }

        if (! $probe) {
            return false;
        }

        // Asked only on a probe path, and memoised for the request anyway.
        try {
            return $segments[0] !== strtolower(trim(AdminPathService::current(), '/'));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function gone(): Response
    {
        return new Response("Gone\n", 410, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            // A day at any cache in front of the shop; the body never changes.
            'Cache-Control' => 'public, max-age=86400',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
