<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SiteHost;
use App\Support\Url;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One shop, four addresses, one of them the real one.
 *
 * Two jobs, and they are opposites on purpose:
 *
 *   AN ALIAS IS FORWARDED.   www.extrabeauty.ae and the retired
 *                            kbeautybliss.com 301 to the canonical host, which
 *                            is what moves their accumulated ranking across.
 *
 *   ANYTHING UNLISTED IS HIDDEN. staging.extrabeauty.ae serves the identical
 *                            shop, so left alone Google indexes a second copy
 *                            of every product page and they compete with the
 *                            real ones. It is NOT redirected -- that would make
 *                            staging unusable, and staging is where releases
 *                            are checked before customers see them -- it is
 *                            marked noindex instead.
 *
 * Which host is which is decided by App\Support\SiteHost, whose class comment
 * carries the argument for why aliases are an allow-list. Short version: on
 * this host there is no shell, so a redirect rule cannot be undone from
 * outside the application, and "redirect anything not canonical" turns one typo
 * into a site nobody can reach.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS NEVER TOUCHED
 * ---------------------------------------------------------------------------
 *
 * ONLY GET AND HEAD ARE FORWARDED. A 301 on a POST is converted to a GET by
 * every browser and the body is dropped, so forwarding a form submission would
 * silently discard an order. A POST to an alias is served where it lands; the
 * page it came from was already on the canonical host in all but the rarest
 * case, and losing a checkout is not worth tidying a URL.
 *
 * THREE PATHS ARE EXEMPT OUTRIGHT, and each one is a real failure this would
 * otherwise cause:
 *
 *   /_kbb-health         UpdateRunner fetches this over HTTP right after it
 *                        writes files, and rolls the update back if it does not
 *                        answer. A 301 here is an update that always rolls
 *                        itself back.
 *
 *   /import-chain/*      the loopback the background import calls to continue
 *                        itself (Lane GO). It is a POST, so the method check
 *                        already covers it -- named anyway, because the cost of
 *                        the belt is nothing and the cost of the braces failing
 *                        is an import that stops when the tab closes, which is
 *                        the one thing that feature exists to prevent.
 *
 *   /kbb-recover.php     the standalone recovery script. It does not boot
 *                        Laravel so it never reaches this middleware, but it is
 *                        listed so that a future reader moving it inside the
 *                        application does not quietly break the escape hatch.
 *
 * ---------------------------------------------------------------------------
 * ONE HOP, NOT TWO
 * ---------------------------------------------------------------------------
 *
 * An old address arriving on the old domain needs two corrections: the host and
 * the path. Done naively that is two 301s -- kbeautybliss.com/toners/ to
 * extrabeauty.ae/toners/ to extrabeauty.ae/product-category/skincare/toners/.
 * Google follows chains, but every hop is latency for a real shopper and one
 * more thing to break.
 *
 * So this consults the same `redirects` table CheckRedirects reads, through
 * CheckRedirects' own lookup(), before building the target. Both corrections
 * travel in one response.
 */
final class CanonicalHost
{
    /** Paths this middleware must never redirect, whatever the host. */
    private const EXEMPT_PREFIXES = [
        '/_kbb-health',
        '/import-chain',
        '/kbb-recover.php',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $verdict = SiteHost::classify($request->getHost());

        if ($this->shouldForward($request, $verdict)) {
            $target = $this->targetFor($request);

            if ($target !== null) {
                return redirect()->away($target, 301);
            }
        }

        $response = $next($request);

        if (SiteHost::isPrivate()) {
            /*
             * THE HEADER AND NOT ONLY THE META TAG.
             *
             * A <meta name="robots"> only exists inside an HTML document, and
             * staging serves more than HTML: sitemap.xml, every product
             * photograph, every PDF invoice. X-Robots-Tag applies to all of
             * them, and it is the form Google documents for non-HTML.
             *
             * `noarchive` as well as noindex, so no cached copy of a staging
             * page survives in a result page while the index catches up.
             */
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }

    /** Only an alias, only when switched on, only a safe method, only outside the exempt paths. */
    private function shouldForward(Request $request, string $verdict): bool
    {
        if ($verdict !== SiteHost::ALIAS) {
            return false;
        }

        if (! SiteHost::redirectEnabled()) {
            return false;
        }

        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        return ! $this->isExempt($request->getPathInfo());
    }

    private function isExempt(string $path): bool
    {
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The absolute URL to forward to, or null if it would be a no-op.
     *
     * Null rather than a self-referencing redirect is the loop guard: if the
     * canonical host somehow resolves to the host already being served, this
     * returns null and the request is served instead of bouncing forever.
     */
    private function targetFor(Request $request): ?string
    {
        $canonical = SiteHost::canonical();

        if ($canonical === '' || $canonical === SiteHost::normalise($request->getHost())) {
            return null;
        }

        $path = $request->getPathInfo();

        /*
         * The path correction, folded into the same response -- see the class
         * comment. getPathInfo() and not path(), matching CheckRedirects
         * exactly: path() strips the trailing slash and `redirects.source` is
         * stored with it.
         *
         * ▲ AND IT IS CheckRedirects' OWN LOOKUP, not a second copy of its
         * query. It used to be a copy, and a copy was survivable only while
         * that class was dead code: now that it is registered as middleware,
         * the two run on the same paths on every request and any difference
         * between them is a redirect that fires on the canonical host and not
         * on an alias -- or, worse, a loop guarded on one host and not the
         * other. lookup() carries the enabled check, the loop refusal and the
         * cached source index, so this host pays no query for an address no
         * row claims either. tests/Feature/RedirectMiddlewareTest.php asserts
         * the two against EACH OTHER rather than against a literal.
         *
         * Not findMatch(): that gates on isMethod('GET'), and this middleware
         * forwards HEAD as well as GET. Going through it would silently stop
         * folding the path correction into a HEAD request's one hop.
         */
        $mapped = CheckRedirects::lookup($path);

        if ($mapped !== null) {
            // Url::redirect() returns an absolute URL on APP_URL, already
            // carrying the base path. Send it unchanged; it is the same string
            // CheckRedirects would have produced on the canonical host.
            return Url::redirect($mapped->target);
        }

        $url = $request->getScheme().'://'.$canonical.Url::raw($path);
        $query = $request->getQueryString();

        return $query === null || $query === '' ? $url : $url.'?'.$query;
    }
}
