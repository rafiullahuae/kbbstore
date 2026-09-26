<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\LocaleSlugs;
use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves `/ar/product/مرطب-الوجه/` without one route, controller or query
 * knowing that a second address exists.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * INERT UNTIL THE POLICY SAYS `translated`, WHICH IS NOT THE SHIPPED VALUE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `LocaleSlugs::translating()` is false while `seo_arabic_slugs` says `shared`,
 * and no row seeds it otherwise, so on every shop as it stands this class does
 * one array-key check and calls $next. docs/SEO-ARABIC-SLUGS.md is the decision
 * and recommends leaving it that way.
 *
 * ── THE SAME TRICK AS SetLocaleFromPath, DELIBERATELY ───────────────────────
 *
 * That class's header makes the argument in full: rewriting the request before
 * the router runs costs one string operation and leaves the router,
 * RESERVED_SLUGS, the redirect map, the sitemap and every route file alone,
 * where a second registered route table would double 790 lines of routes on a
 * host whose route cache can only be cleared by a migration. The Arabic slug is
 * the same shape of problem one level down — a second spelling of a path — so it
 * gets the same answer, and `rewrite()` below is that class's method, for the
 * reasons its comment gives (duplicate(), not mutation, because getPathInfo(),
 * getRequestUri(), getBaseUrl() and getBasePath() all memoise).
 *
 * ── WHERE IT GOES IN THE STACK, AND WHY THE ORDER IS NOT A PREFERENCE ───────
 *
 *     CanonicalHost  →  SetLocaleFromPath  →  CheckRedirects  →  THIS
 *
 * AFTER SetLocaleFromPath, because it needs the bound locale and the path with
 * /ar already off it.
 *
 * AFTER CheckRedirects, AND THIS ONE IS A LOOP IF IT IS WRONG. Switching the
 * policy on writes a redirect row per row, in Arabic only, pointing the OLD
 * Arabic address (`/product/anua-heartleaf-toner/`, locale `ar`) at the new one.
 * If this class ran first it would rewrite the new Arabic address back to the
 * English slug, that row would then fire, and the visitor would bounce between
 * the two for ever. Run second, the redirect fires for the old address and this
 * rewrite happens for the new one, which is the only arrangement where both
 * jobs are done once.
 *
 * ── WHAT IT REFUSES TO DO ───────────────────────────────────────────────────
 *
 * It never invents a 404 and never invents a 301. A path with no row behind it
 * is passed through untouched and answered by whatever would have answered it
 * before — so a mistyped Arabic address gets the same 404 an English one does,
 * from the same controller, with the same body.
 *
 * It does not touch English. `toCanonicalPath()` returns null for the default
 * locale, so the unprefixed shop cannot be affected by anything in this file
 * even with the policy on. That is the rule-1 guarantee stated where it is
 * enforced rather than where it is promised.
 */
class ResolveLocaleSlugs
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! LocaleSlugs::translating()) {
            return $next($request);
        }

        $locale = Locale::current();

        if ($locale === Locale::DEFAULT) {
            return $next($request);
        }

        /*
         * rawurldecode() FIRST, because getPathInfo() is the request line
         * verbatim and an Arabic address arrives escaped from every real client:
         * `%D8%A7…` from a browser, `%d8%a7…` from an old WordPress permalink.
         * Both decode to the bytes `locale_slugs.slug` holds, which is why one
         * comparison serves both — the same reasoning, and the same measurement,
         * as CheckRedirects::spellings().
         *
         * A decode that changes the number of segments is not another spelling
         * of this address but a different one, so it is left alone: `%2F` must
         * not be able to reach a row under a path it is not at.
         */
        $path = $request->getPathInfo() ?: '/';
        $decoded = rawurldecode($path);

        if (substr_count($decoded, '/') !== substr_count($path, '/')
            || ! mb_check_encoding($decoded, 'UTF-8')) {
            $decoded = $path;
        }

        $canonical = LocaleSlugs::toCanonicalPath($decoded, $locale);

        if ($canonical === null) {
            return $next($request);
        }

        return $next($this->rewrite($request, $canonical));
    }

    /**
     * The same request, asking for $path instead.
     *
     * Lifted from SetLocaleFromPath::rewrite() unchanged, including the header
     * copy-back — duplicate() rebuilds the header bag from the server bag, which
     * is lossless under PHP-FPM and loses a header set directly on the Request
     * object, which is what a test client does.
     */
    private function rewrite(Request $request, string $path): Request
    {
        $server = $request->server->all();

        $uri = rtrim($request->getBaseUrl(), '/') . $path;
        $query = $request->getQueryString();

        $server['REQUEST_URI'] = $query === null ? $uri : $uri . '?' . $query;

        $headers = $request->headers->all();

        $duplicate = $request->duplicate(null, null, null, null, null, $server);

        foreach ($headers as $key => $values) {
            $duplicate->headers->set($key, $values);
        }

        return $duplicate;
    }
}
