<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Internal link builder.
 *
 * Every storefront path is written as it will appear in production — "/shop/",
 * "/product/foo/", "/my-account/" — and this prefixes the configured base path
 * at render time.
 *
 * Why not Laravel's url() helper: it derives the prefix from the incoming
 * request, which is right for most apps but wrong here. The URL Contract fixes
 * these paths exactly, so the prefix has to be an explicit setting we control,
 * not something inferred per request and liable to differ behind a proxy.
 *
 * Trailing slashes are preserved deliberately (U-01). WordPress uses them and
 * Laravel does not, so dropping one turns an indexed URL into a redirect.
 *
 * Since 2.60.200 this also carries the LANGUAGE prefix — see to() below and
 * App\Support\Locale for why that belongs here and not at 194 call sites.
 */
final class Url
{
    /**
     * The path prefix the site is served under.
     *
     * Derived from the request first, config second — deliberately that way
     * round. config('kbb.base_path') depends on config/kbb.php existing, on
     * KBB_BASE_PATH being in .env, and on the config cache being current. Any
     * one of those going wrong silently emits root-relative links, which is how
     * the checkout button ended up pointing at easywebsol.com/checkout.
     *
     * Request::getBaseUrl() is the prefix the front controller is actually
     * served from — "/kbb-upgrade" here, "" at a domain root. It cannot go
     * stale, cannot be cached wrong, and needs no configuration. If it is ever
     * necessary to override it, KBB_BASE_PATH still wins.
     *
     * Memoised: called once per link, and there can be a hundred on a page.
     */
    private static ?string $base = null;

    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        // An explicit setting always wins, so the behaviour stays overridable.
        $configured = trim((string) config('kbb.base_path', ''), '/');

        if ($configured !== '') {
            return self::$base = '/' . $configured;
        }

        // Console commands have no request; a root-relative path is correct there.
        if (! app()->runningInConsole() && app()->bound('request')) {
            return self::$base = rtrim((string) request()->getBaseUrl(), '/');
        }

        return self::$base = '';
    }

    /**
     * Drop the memo so the next base() re-derives it.
     *
     * Needed because the memo is process-level and base() reads the REQUEST
     * when no KBB_BASE_PATH is configured. One request per process under
     * PHP-FPM hides that completely; anything serving two requests from one
     * process — the test suite, a queue worker, Octane — would otherwise
     * prefix the second request's links with the first request's base. In the
     * suite that is an order dependency: whichever test resolved it first
     * decides the prefix for every test after it, including tests that set
     * kbb.base_path themselves. Tests\Support\StaticMemos calls this between
     * tests; nothing in the application needs to.
     */
    public static function forgetBase(): void
    {
        self::$base = null;
    }

    /** Test seam, and used by the cart diagnostics. */
    public static function debugBase(): array
    {
        return [
            'configured' => (string) config('kbb.base_path', ''),
            'request_base_url' => app()->runningInConsole() ? null : request()->getBaseUrl(),
            'resolved' => self::base(),
            'app_url' => (string) config('app.url'),
        ];
    }

    /**
     * A storefront path, prefixed for the current environment AND language.
     *
     * THE LANGUAGE PREFIX IS ADDED HERE AND NOWHERE ELSE. Every internal link
     * in this application already comes through this method — 194 call sites,
     * plus the self-referencing canonical in layouts/store.blade.php, which is
     * built from the request path and handed straight back through here. So an
     * Arabic page links to Arabic pages and canonicalises to its own Arabic
     * address without a single one of those call sites being edited, and a link
     * a later lane writes is bilingual the day it is written.
     *
     * The two prefixes compose in the only order that can be right:
     * base first, language second — /kbb-upgrade/ar/shop/. The base path is
     * where the application is MOUNTED (a fact about the server) and the
     * language is a fact about the PAGE, so the deployment prefix has to be
     * outermost or the front controller would never be reached.
     *
     * App\Support\Locale::localisable() is what keeps /wp-content/uploads/…
     * and /admin-api/… out of it: those are served off disk or by the back
     * office, and a prefixed copy of either is a 404. media() below does not
     * come through this path at all, for the same reason twice over.
     *
     * In English — Locale::segment() === '' — this is byte-for-byte what it
     * always returned, so nothing changes for the shop as it stands today.
     */
    public static function to(string $path = '/'): string
    {
        $base = self::base();

        if ($path === '' || $path === '/') {
            $path = '/';
        } elseif (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path)) {
            // Absolute URLs and mailto/tel links pass through untouched.
            return $path;
        }

        /*
         * THE FAST PATH, AND IT IS NOT AN OPTIMISATION FOR ITS OWN SAKE.
         *
         * This method is called around two hundred times on a storefront page.
         * Locale::withSegment() splits the path, consults
         * AdminPathService::current() and checks a settings-backed switch — all
         * of which is wasted work when the answer is "no prefix", which is
         * EVERY English page, which is every page this shop serves today.
         *
         * Locale::segment() answers that in an array lookup: the default locale
         * short-circuits before any setting is read. Measured as a per-test
         * timeout in the suite when this check was not here, which is the
         * honest reason it is.
         */
        if (Locale::segment() !== '') {
            /*
             * Lane S5, docs/SEO-ARABIC-SLUGS.md section 5. With the Arabic-slug
             * policy on, an internal link built here still pointed at the shared
             * address, so every Arabic product card cost the shopper one extra
             * 301 hop through ResolveLocaleSlugs. Correct and indexed correctly
             * -- just a hop more than needed.
             *
             * INSIDE this branch deliberately, not above it. The guard is the
             * fast path the comment above describes, and it is empty on every
             * English page, which is every page this shop serves today: so an
             * English render does not pay for this at all, not even the cached
             * settings read that LocaleSlugs::translating() would cost. An
             * Arabic render pays one array lookup, and only answers differently
             * once a policy of `translated` and a filled-in Arabic slug both
             * exist.
             */
            $display = \App\Support\LocaleSlugs::toDisplayPath($path, Locale::current());

            $path = Locale::withSegment($display ?? $path);
        }

        return self::raw($path);
    }

    /**
     * The same, with no language prefix.
     *
     * For paths the web server answers without PHP, and for anywhere that needs
     * the one canonical address of a document rather than this reader's copy.
     */
    public static function raw(string $path = '/'): string
    {
        $base = self::base();

        if ($path === '' || $path === '/') {
            return $base === '' ? '/' : $base . '/';
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return $path;
        }

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * A media path under /wp-content/uploads.
     *
     * raw(), never to(): these files are served off disk by the web server and
     * PHP never sees the request, so /ar/wp-content/uploads/foo.jpg is a 404
     * for an image on every Arabic page. An image has no language.
     */
    public static function media(string $path): string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return $path;
        }

        $root = (string) config('kbb.media_root', '/wp-content/uploads');
        $path = ltrim($path, '/');

        // Accept paths already carrying the root, so importers can store either form.
        if (str_starts_with('/' . $path, $root)) {
            return self::raw($path);
        }

        return self::raw(ltrim($root, '/') . '/' . $path);
    }

    /**
     * An absolute URL for a path that ALREADY carries the language it wants.
     *
     * WHY THIS IS NOT redirect(). redirect() goes through to(), and to()
     * re-localises: it strips whatever locale segment the path carries and
     * applies the CURRENT one. That is right for an ordinary internal link
     * written as '/shop/' and wrong for a path that was deliberately built for
     * another language — which is exactly what an hreflang alternate is. Passed
     * through to(), every alternate on an English page came back pointing at
     * the English page, so the tag said "the Arabic version of this page is
     * this page".
     *
     * So this one uses raw(): base path, no locale, absolute.
     */
    public static function absolute(string $path = '/'): string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return $path;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $base = self::base();

        if ($base !== '' && str_ends_with($appUrl, $base)) {
            $appUrl = substr($appUrl, 0, -strlen($base));
        }

        return rtrim($appUrl, '/') . self::raw($path);
    }

    /**
     * A URL safe to hand to redirect() — IN-BAND, for the visitor being answered.
     *
     * to() returns a root-relative path with the base prefix, which is right for
     * an href — the browser resolves it against the host. redirect() is
     * different: Laravel resolves a relative path against APP_URL, and APP_URL
     * already ends in the base path. Passing to() there produced
     * /kbb-upgrade/kbb-upgrade/cart and a 404.
     *
     * This returns an absolute URL, which Laravel passes through untouched, so
     * the base appears exactly once however APP_URL is written.
     *
     * ── WHY THE ORIGIN IS THE REQUEST'S AND NOT APP_URL ───────────────────────
     *
     * It used to be `config('app.url')`, and that is the defect Lane N measured:
     * with APP_URL still at the old domain, `GET https://new-shop.test/checkout`
     * answered `302 Location: https://old-shop.test/cart/`. The shopper is on
     * new-shop.test, their session cookie is scoped to new-shop.test, and they
     * have just been thrown onto a host that cannot see their basket — on the
     * way to paying. Once the old domain stops resolving it is a dead end.
     *
     * A redirect Location is IN-BAND: the visitor is already on this host, so
     * answering with it is both correct and harmless. A forged `Host:` redirects
     * the forger to their own domain and nobody else's.
     *
     * THAT ARGUMENT DEPENDS ON ONE VISITOR'S Location NEVER REACHING ANOTHER,
     * and that was checked rather than assumed. App\Http\Middleware\CacheHeaders
     * ships switched off (CacheSettings::enabled() is false until the owner turns
     * it on at Platform → Cache); when it IS on, it returns early on any response
     * whose status is not 200, so a 302 keeps Symfony's computed `no-cache,
     * private`; the storefront knob it does set cannot reach `public` at any
     * value — CacheSettings::storefrontHeader() emits `private` on both branches;
     * and /cart, /checkout and /my-account are `no-store` before the status test
     * is even reached. There is no path by which a 302 leaves here cacheable by
     * a shared cache.
     *
     * OUT-OF-BAND CALLERS MUST USE external() INSTEAD — see it below.
     *
     * ── WHY THE REQUEST IS A PARAMETER ────────────────────────────────────────
     *
     * SiteUrl::origin() takes the request rather than reaching for it, because
     * `app()->runningInConsole()` is true under PHPUnit as well as under artisan.
     * Passing null is safe and correct — under PHP-FPM origin() picks the bound
     * request up itself, so every call site is fixed in production whether or not
     * it threads one — but it is also INVISIBLE TO THIS SUITE, where the console
     * branch always answers APP_URL. Measured, not reasoned about: inside a
     * feature request to http://new-shop.test, SiteUrl::origin() returns
     * `http://localhost` and SiteUrl::origin($request) returns
     * `http://new-shop.test`.
     *
     * So a caller that HOLDS a request hands it over, and the behaviour it is
     * relying on is then the behaviour its test exercises. A caller that does not
     * hold one passes nothing and keeps exactly what it emits today.
     */
    public static function redirect(string $path = '/', ?\Illuminate\Http\Request $request = null): string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return $path;
        }

        return SiteUrl::origin($request) . self::to($path);
    }

    /**
     * An absolute URL for somebody who is NOT the person who made the request.
     *
     * OUT-OF-BAND: an email, a webhook callback registered at a payment provider,
     * a link a mail client has no origin to resolve. The reader is not the
     * sender, so a `Host:` header has no standing here and there is deliberately
     * no request fallback — a `Host:` a visitor chose, written into a
     * password-reset email, is account takeover, not a cosmetic bug.
     *
     * This is byte-for-byte what redirect() used to return: SiteUrl::
     * externalOrigin() is APP_URL with its path taken off, which is the same
     * string the old body built by stripping the base path off the end of
     * APP_URL. Every consumer moved here therefore emits exactly what it emitted
     * before, on a shop served from the address it is configured with and on one
     * that is not.
     *
     * Empty string when APP_URL is unusable, which yields a root-relative path —
     * the same thing the old body returned for an empty APP_URL, and the safe
     * direction to fail in. It must never reach for the request instead.
     */
    public static function external(string $path = '/'): string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return $path;
        }

        return SiteUrl::externalOrigin() . self::to($path);
    }

    /**
     * The same, for a root-relative URL this application has ALREADY built.
     *
     * external() takes a bare storefront path and runs it through to(), which
     * adds the base path and the locale segment. A URL that has been through
     * to() once already must not go through it twice — that is the
     * `/kbb-upgrade/kbb-upgrade/…` trap redirect()'s own history records — so
     * this one only prefixes the confirmed origin.
     *
     * For the case where a path is built for the storefront and ALSO needed in
     * an email: QuizRoutineLink::map() is read by the quiz page, where a
     * root-relative link is right, and by the plan email, where it is dead.
     */
    public static function externalise(string $url): string
    {
        if ($url === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $url)) {
            return $url;
        }

        return SiteUrl::externalOrigin() . '/' . ltrim($url, '/');
    }
}
