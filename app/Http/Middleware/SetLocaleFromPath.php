<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns /ar/shop/ into "the shop page, in Arabic".
 *
 * ── WHY THIS IS GLOBAL AND NOT IN THE `web` GROUP ───────────────────────────
 *
 * A group middleware runs AFTER the router has matched a route, which is too
 * late to change which route matches. This one has to run before the router
 * sees the request, and the only pipeline that runs before the router is the
 * global one. That is the single reason it is registered in bootstrap/app.php
 * rather than from a route file.
 *
 * ── WHY IT REWRITES THE REQUEST RATHER THAN THE ROUTE TABLE ─────────────────
 *
 * The alternative is registering every storefront route a second time inside
 * Route::prefix('ar'), which is what most Laravel localisation packages do.
 * Here that would mean restructuring routes/web.php — 790 lines of inline
 * routes plus thirty-five requires — and it would double the route table, which
 * is a real cost on a host whose route cache has to be deleted by a migration
 * rather than rebuilt by a command.
 *
 * Stripping the segment costs one string operation per request and leaves the
 * router, RESERVED_SLUGS, the redirect map, the sitemap and every route file
 * exactly as they are. A route a later lane writes is reachable in both
 * languages the day it is written, without its author knowing this class
 * exists.
 *
 * ── HOW THE REWRITE IS DONE SAFELY ──────────────────────────────────────────
 *
 * Symfony's Request has no public path setter, and mutating the server bag in
 * place does NOT work: getPathInfo(), getRequestUri(), getBaseUrl() and
 * getBasePath() each memoise on first call, so the request would keep answering
 * with the address it was built from. duplicate() is the supported way — it
 * clones, replaces the bags handed to it, and explicitly nulls all four of
 * those memos (Symfony\Component\HttpFoundation\Request::duplicate).
 *
 * The duplicate is passed to $next rather than assigned over the original,
 * because Kernel::dispatchToRouter() rebinds the container's `request` to
 * whatever the global pipeline hands it. So the controller, the views, the
 * session and Url::base() all see the rewritten request and agree with each
 * other.
 *
 * headers are copied back after duplicate(), because duplicate() rebuilds the
 * header bag from the server bag. Under PHP-FPM every header IS in $_SERVER so
 * that is lossless, but a header set directly on the Request object — which is
 * what a test client and some proxy middleware do — would otherwise vanish.
 *
 * ── WHAT /en/ DOES ──────────────────────────────────────────────────────────
 *
 * 301 to the unprefixed form, preserving the query string. The owner asked for
 * /en to exist; serving the same page at two addresses would be duplicate
 * content, so it exists as a redirect. That is also the escape hatch if this
 * decision is ever revisited: flipping English to prefixed means changing one
 * `segment` in Locale::LOCALES and turning this redirect around.
 *
 * ── IF THIS MIDDLEWARE IS NEVER REGISTERED ──────────────────────────────────
 *
 * Nothing breaks. The site stays English-only and /ar/... 404s, because the
 * router has no route for it. That is deliberate: the registration is a
 * hand-applied edit to bootstrap/app.php (packages cannot ship bootstrap/ — see
 * BuildPackage::NEVER_SHIP and UpdateGuard), so the failure mode of forgetting
 * it has to be "Arabic is not live yet", not "the shop is down".
 */
class SetLocaleFromPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo() ?: '/';

        [$locale, $rest] = Locale::splitPath($path);

        if ($locale === null) {
            app()->setLocale(Locale::DEFAULT);

            return $next($request);
        }

        // A prefix on something the web server serves off disk, or on the back
        // office. Not a locale — leave the path alone and let it 404 honestly
        // rather than quietly serving a second copy of a static file's route.
        if (! Locale::localisable($rest)) {
            app()->setLocale(Locale::DEFAULT);

            return $next($request);
        }

        /*
         * /en/... — the address the owner asked for, answered with one hop to
         * the canonical unprefixed form.
         *
         * A RedirectResponse built directly, NOT redirect()->to().
         *
         * Laravel's UrlGenerator::format() rtrims a trailing slash, and every
         * storefront route in this shop is declared WITH one (URL Contract
         * U-01, and the long note at the top of layouts/store.blade.php about
         * what dropping it cost the canonical). Through redirect()->to(),
         * /en/my-wishlist/ landed on /my-wishlist — which is a second redirect
         * away from the page, so the address the owner asked for would have
         * cost two hops and shed link equity on the way.
         */
        if (Locale::segment($locale) === '') {
            $target = $request->getSchemeAndHttpHost() . rtrim($request->getBaseUrl(), '/') . $rest;
            $query = $request->getQueryString();

            return new \Illuminate\Http\RedirectResponse(
                $query === null ? $target : $target . '?' . $query,
                301,
            );
        }

        app()->setLocale($locale);

        return $next($this->rewrite($request, $rest));
    }

    /**
     * The same request, asking for $path instead.
     *
     * REQUEST_URI carries the base path and the query string; pathInfo does
     * not. Rebuilding it from getBaseUrl() rather than from string surgery on
     * the old REQUEST_URI is what makes this correct under KBB_BASE_PATH:
     * /kbb-upgrade/ar/shop/?paged=2 becomes /kbb-upgrade/shop/?paged=2, with
     * the deployment prefix kept and the locale segment removed.
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
