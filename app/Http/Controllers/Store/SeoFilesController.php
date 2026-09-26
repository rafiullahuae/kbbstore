<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Seo\SeoSettings;
use App\Support\ConcernCollections;
use App\Support\Locale;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class SeoFilesController extends Controller
{
    /**
     * ── THE THREE CRAWL FILES, AND WHY THEY CARRIED NO CACHE HEADER ─────────
     *
     * /sitemap.xml, /robots.txt and /llms.txt are the only documents this
     * application serves that are the SAME BYTES FOR EVERY VISITOR. No cart
     * badge, no signed-in name, no CSRF token, nothing keyed to a person. They
     * are also the three most-refetched URLs on the site: every crawler that
     * visits asks for robots.txt first, and the sitemap is rebuilt from a walk
     * of the whole catalogue — the expensive one to compute and the cheapest
     * one to reuse.
     *
     * They left here with no Cache-Control at all, so Symfony computed
     * `no-cache, private` for them exactly as it does for a product page, and
     * every crawler hit paid for the full catalogue walk again.
     *
     * ▲ AND THE REASON THAT WAS NOT SIMPLY FIXED, WHICH IS THE WHOLE DESIGN OF
     * THIS BLOCK. These routes are declared in routes/web.php and therefore run
     * in the `web` middleware group, which starts a session and leaves a
     * `Set-Cookie` on the way out — the session cookie from StartSession and,
     * on a GET, the XSRF-TOKEN cookie from ValidateCsrfToken. A response that
     * says `Cache-Control: public` AND carries a `Set-Cookie` is a shared-cache
     * hazard of the exact kind CacheHeaders' docblock describes: one visitor's
     * cookie handed to the next reader out of the cache. In practice most
     * proxies refuse to store such a response at all, so the header would have
     * been decoration; the ones that do store it are the ones that hurt.
     *
     * So the header is not the fix on its own. The fix is TWO halves:
     *
     *   1. the routes leave the stateful half of the `web` group — the one
     *      line in routes/web.php this lane does not own, written out below in
     *      self::STATELESS;
     *   2. this controller marks the three documents publicly cacheable, but
     *      ONLY on a request that has no session bound to it.
     *
     * Half 2 is what makes half 1 safe to apply at leisure. Until the route
     * line lands, `$request->hasSession()` is true here, nothing is set, and
     * these three files leave with exactly the bytes and exactly the headers
     * they leave with today — so the package can ship first and change nothing.
     * The day the route line lands, the header appears, and it CANNOT appear
     * beside a Set-Cookie because the only thing that puts one there is the
     * middleware whose absence is being tested for. Fail-closed by
     * construction, not by remembering.
     *
     * ── THE LINE FOR routes/web.php ────────────────────────────────────────
     *
     * Wrap the three existing route declarations:
     *
     *     Route::withoutMiddleware(SeoFilesController::STATELESS)->group(function () {
     *         Route::get('/sitemap.xml', [SeoFilesController::class, 'sitemap']);
     *         Route::get('/robots.txt',  [SeoFilesController::class, 'robots']);
     *         Route::get('/llms.txt',    [SeoFilesController::class, 'llms']);
     *     });
     *
     * A route file change needs the compiled route cache cleared, so the
     * package carries 2026_12_11_000002_clear_caches_seo_file_cache_headers.
     *
     * ── WHAT IS DELIBERATELY *NOT* DROPPED ─────────────────────────────────
     *
     * Not `web` wholesale. `$middleware->web(append: [...])` in bootstrap/app.php
     * puts SecurityHeaders and NoIndexStaging in that group, and NoIndexStaging
     * is what stamps `X-Robots-Tag: noindex` on a staging copy. Dropping the
     * group would take a staging sitemap's noindex off with it — the precise
     * accident robots() below spends thirty lines explaining it must not make.
     * Only the five classes that touch cookies and the session go; everything
     * else about these responses is what it was. (CanonicalHost is global, not
     * grouped, so a private install's X-Robots-Tag is unaffected either way.)
     */
    public const STATELESS = [
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ];

    /**
     * One hour, shared caches included.
     *
     * A CONSTANT AND NOT A SETTING, said plainly because the alternative was
     * considered. Platform → Cache governs the storefront's HTML, where the
     * number is a real trade (a stale cart badge against a saving) and the
     * screen is the right place to make it. There is no such trade here: these
     * three documents have no per-visitor content to go stale, and an hour is
     * shorter than the interval at which any crawler refetches them — Google
     * holds robots.txt for up to twenty-four hours on its own account. A slider
     * for it would be a control whose every position is equally correct.
     *
     * It is also NOT gated on CacheSettings::enabled(). That switch exists so
     * that APPLYING A PACKAGE cannot silently change the headers of a live
     * shop; here the route line is that gate, and it is applied by hand, once,
     * by whoever is ready for it. Two gates would only mean the owner flips two
     * things to get one effect and half-remembers which.
     *
     * `s-maxage` is stated as well as `max-age` rather than left to inherit,
     * because the whole point of the route change above is that a SHARED cache
     * may now hold these — so the number a shared cache reads should be in the
     * header rather than implied by it.
     */
    public const PUBLIC_CACHE = 'public, max-age=3600, s-maxage=3600';

    /**
     * Mark a crawl file publicly cacheable — but only once it is really public.
     *
     * THE GUARD IS `hasSession()`, AND IT IS NOT A PROXY FOR THE THING, IT IS
     * THE THING. Every `Set-Cookie` these responses could carry comes from the
     * session middleware: StartSession writes the session cookie, and
     * ValidateCsrfToken writes XSRF-TOKEN only after reading
     * `$request->session()`. Laravel binds the session onto the request in
     * StartSession::handle() and nowhere else, so "no session on this request"
     * and "nothing will attach a cookie to this response" are one condition
     * observed from one side. There is no window in which this returns false
     * and a cookie still arrives.
     *
     * It cannot be checked on the RESPONSE instead, which is the obvious
     * alternative and the wrong one: those cookies are added by middleware that
     * runs after this controller returns, so a response inspected here is
     * always cookie-free and the check would always pass.
     *
     * 200 ONLY. A disabled sitemap is a 404 and a private install's robots.txt
     * is a different document from a public one's; neither is worth pinning
     * into a shared cache for an hour, and a 404 that caches is a 404 that
     * outlives the setting that caused it.
     */
    private function publiclyCacheable(Request $request, Response $response): Response
    {
        if ($request->hasSession() || $response->getStatusCode() !== 200) {
            return $response;
        }

        $response->headers->set('Cache-Control', self::PUBLIC_CACHE);

        return $response;
    }
    /**
     * The per-visitor paths robots.txt keeps crawlers off.
     *
     * The same set as App\Support\Indexability::PRIVATE_PREFIXES, in the order
     * this file has always printed them, so the English bytes of robots.txt do
     * not move. It is a second list rather than a use of the first ONLY because
     * of that order, and SeoBilingualTest asserts the two sets are equal — so a
     * private prefix added to one and not the other fails the suite instead of
     * shipping a crawlable account page.
     */
    private const ROBOTS_PRIVATE = [
        '/checkout', '/cart', '/my-account', '/my-wishlist', '/wishlist', '/track-my-order',
    ];

    /**
     * The absolute base every URL in these files is built on.
     *
     * Was `$s['site_url'] ?? config('app.url') ?? url('/')`. The SEO screen
     * posts site_url on every save, so an admin who never filled it in has
     * '' stored there -- which ?? happily accepts, because '' is not null.
     * The result was a sitemap of relative <loc> values (rejected outright by
     * Search Console) and a robots.txt advertising "Sitemap: /sitemap.xml".
     * SeoSettings::firstFilled() takes the first candidate that is usable
     * rather than the first that exists.
     *
     * ── AND url('/') HAS NOW GONE, WHICH IS THE POINT OF THIS COMMENT ────────
     *
     * The third candidate was `url('/')`, which is REQUEST-DERIVED: Laravel
     * builds it from the `Host:` header, and behind a trusted proxy from
     * `X-Forwarded-Host`. Both are chosen by whoever sent the request.
     *
     * A sitemap and a robots.txt are the definition of OUT-OF-BAND — the reader
     * is Googlebot, or Bing, or an IndexNow endpoint, and never the person whose
     * request produced the bytes. A `<loc>` built from a stranger's `Host:` asks
     * a search engine to index this shop's catalogue under that stranger's
     * domain, and `Sitemap: https://attacker.example/sitemap.xml` in robots.txt
     * asks it to go and fetch the next instruction from them.
     *
     * It was unreachable, and only by accident: config/app.php spells
     * `env('APP_URL', 'http://localhost')`, so the second candidate is never
     * blank unless somebody writes a bare `APP_URL=` into .env — one keystroke
     * in a file this project's own installer and Platform → Site address both
     * write. A Host-header path into sitemap.xml that is closed by a default in
     * an unrelated file is not closed.
     *
     * What is lost by removing it: with BOTH site_url and APP_URL blank the
     * base is now '' and these files carry root-relative addresses, which is the
     * misconfiguration the paragraph above describes and Search Console rejects
     * outright. Visibly broken and harmless beats plausible and attacker-chosen.
     * Every other base chain in the SEO layer (Support\Seo::canonical() and
     * friends) already stops at APP_URL; this was the last one that did not.
     */
    private function base(): string
    {
        $s = SeoSettings::map();

        return rtrim(SeoSettings::firstFilled(
            $s['site_url'] ?? null,
            (string) config('app.url'),
        ), '/');
    }

    /**
     * The curated listings, as the ROUTER serves them: a leading and trailing
     * slash, registration order, no duplicates.
     *
     * `CollectionController@show` and not `@concern`: the concern listings are
     * one parameterised route whose live set is a question for
     * ConcernCollections, and the block that adds them asks it. A route with
     * parameters cannot be turned into an address from its URI alone, which is
     * the same distinction SourceReachability draws.
     *
     * @return list<string>
     */
    private static function curatedListingPaths(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (! str_ends_with((string) $route->getActionName(), 'CollectionController@show')) {
                continue;
            }

            if ($route->parameterNames() !== []) {
                continue;
            }

            $path = '/' . trim($route->uri(), '/') . '/';

            if ($path !== '//' && ! in_array($path, $out, true)) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * The content pages routes/web.php actually routes: the row's slug mapped
     * to the ADDRESS the router serves it at.
     *
     * Each of the seven is registered as a literal route carrying
     * `->defaults('slug', 'about')` and friends, which is where
     * PageController::show() reads the slug from — so the key is the
     * controller's own string rather than a copy of it. A row that is not
     * published is still excluded by the query below: PageController::show()
     * 404s it, and a sitemap entry that 404s is a Search Console error.
     *
     * ── WHY BOTH HALVES, WHEN THEY ARE THE SAME STRING SEVEN TIMES ──────────
     *
     * The slug is what identifies the ROW and the URI is what identifies the
     * ADDRESS, and this file needs one of each: it looks the row up by slug and
     * publishes the address. For all seven pages the two are spelled the same,
     * so keeping them apart buys nothing today — and that is exactly the
     * unstated assumption worth removing, because the line that used to build
     * the URL did `'/' . $page->slug . '/'`. Register `/about-us` with
     * `->defaults('slug', 'about')` and the shop serves the page at /about-us/
     * while the sitemap advertises /about/, which 404s.
     *
     * Not hypothetical in kind: `/everything-under-54-aed` is served from the
     * key `under-54` one block up, so this application already does exactly
     * this for its curated listings. It has simply never done it for a page.
     *
     * @return array<string, string> slug => path, with both slashes
     */
    private static function routedPagePaths(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (! str_ends_with((string) $route->getActionName(), 'PageController@show')) {
                continue;
            }

            if ($route->parameterNames() !== []) {
                continue;
            }

            $slug = (string) ($route->defaults['slug'] ?? '');
            $path = '/' . trim($route->uri(), '/') . '/';

            if ($slug !== '' && $path !== '//' && ! isset($out[$slug])) {
                $out[$slug] = $path;
            }
        }

        return $out;
    }

    /**
     * Does this `seo` value ask for noindex?
     *
     * Three tables now, not one: `products.seo`, `brands.seo` and
     * `categories.seo` all carry the same shape — the migration that added the
     * latter two says so in as many words — and a page that sets noindex must
     * drop out of this file whichever table it lives in. Google reports the
     * pair "page says noindex, sitemap says crawl me" as an error against the
     * property rather than quietly honouring the page.
     *
     * The value arrives as a raw json string from the query builder (the
     * Eloquent cast is not in play here), and the flag itself has been written
     * by hand, by the importer and by the admin screen, so it can be true, 1 or
     * "1". Anything truthy counts; anything unparseable does not.
     */
    private static function isNoindex(mixed $seo): bool
    {
        if (is_string($seo)) {
            $seo = json_decode($seo, true);
        }

        return is_array($seo) && ! empty($seo['noindex']);
    }

    /** GET /sitemap.xml — dynamic sitemap of indexable URLs. */
    public function sitemap(Request $request)
    {
        $s = SeoSettings::map();
        if (SeoSettings::from($s, 'sitemap_enabled') === '0') {
            return response('Sitemap disabled', 404);
        }

        $base = $this->base();

        /*
         * ── PRODUCT IMAGES IN THE SITEMAP, AND WHY THE SWITCH SHIPS OFF ──
         *
         * Google Images is a real entry point for a shop that sells things
         * people look at before they read about, and an <image:image> under a
         * product's <url> is how a sitemap says "these pictures belong to this
         * page" — which is the association Google otherwise has to infer from
         * markup it may never fetch.
         *
         * This is where the competitor is beaten rather than matched. Shopify
         * generates its product sitemap with the FEATURED image only, one
         * <image:image> per product; nothing in the platform emits the rest of
         * the gallery. Ours emits the whole gallery, de-duplicated, in order,
         * because `products.images` is already selected on the query that is
         * already running — the extra pictures cost one column, not one query.
         *
         * ONLY <image:loc>. Google withdrew support for <image:caption>,
         * <image:title>, <image:license> and <image:geo_location>; they are
         * parsed and ignored. Emitting them would be bytes on every product
         * entry buying nothing, so the entry carries the one element that is
         * still read.
         *
         * OFF BY DEFAULT, and that is rule 1 rather than timidity: /sitemap.xml
         * is byte-pinned by the English render walk, and a package that moves
         * a file Search Console has already fetched should move it because an
         * operator decided to, on a day they can watch what happens. With the
         * box unticked this method emits the bytes it emits today and runs the
         * same queries — the images column is not even selected.
         *
         * Store → SEO & Meta → Settings · Sitemap & robots · "Product images
         * in sitemap".
         */
        $withImages = SeoSettings::from($s, 'sitemap_images', '0') === '1';

        $urls = [];
        $add = function ($loc, $lastmod = null, $priority = '0.6', $freq = 'weekly', array $images = []) use (&$urls) {
            $urls[] = compact('loc', 'lastmod', 'priority', 'freq', 'images');
        };

        // Static pages
        $add($base . '/', null, '1.0', 'daily');
        // Trailing slash, for the same reason the product entries carry one:
        // the shop page canonicalises to /shop/, so submitting /shop asks
        // Google to fetch a URL that then points somewhere else. Every other
        // entry in this file already used the slashed form.
        $add($base . '/shop/', null, '0.9', 'daily');
        /*
         * /reviews/ ONLY WHEN THERE IS A REVIEW ON IT.
         *
         * This entry was unconditional, and for the whole life of the repo the
         * page it advertised was twelve invented customers in a JavaScript
         * array (see store/review-wall.blade.php and Support\ReviewWall). The
         * page is now built from `reviews` and says "No reviews yet" when there
         * are none — which is honest, and is not a page to ask Google to index.
         * A sitemap is a recommendation, and recommending a page whose entire
         * content is a statement that it has no content is what Search Console
         * calls thin content.
         *
         * NOT a 404 and NOT a noindex, which are the two heavier tools and both
         * wrong here. The page is reachable from the home page's review wall and
         * may be linked or bookmarked, so 404 would break real links; noindex
         * would keep it out of the index even after the shop earns reviews,
         * since nothing would prompt a re-crawl. Absent from the sitemap is the
         * lightest of the three: the page stays a 200, stays crawlable, and
         * simply is not advertised until it has something to say.
         *
         * One query, guarded like every other table in this file so a
         * half-migrated database serves a short sitemap instead of a 500.
         */
        if (Schema::hasTable('reviews')) {
            $query = DB::table('reviews')
                ->where('status', 'approved')
                ->whereNotNull('content')
                ->where('content', '<>', '');

            /*
             * The same definition of "has a review" that the page itself uses.
             *
             * Support\ReviewWall now excludes demo-seeded rows, so a shop whose
             * only reviews were seeded renders "No reviews yet" on /reviews. A
             * sitemap built from the unfiltered table would go on submitting
             * that page to Google — spending crawl budget to advertise an empty
             * state, and reproducing in miniature the defect 2.60.192 fixed,
             * where the sitemap promoted a page of invented customers.
             */
            \App\Support\DemoReviews::excludeQuery($query);

            if ($query->exists()) {
                $add($base . '/reviews/', null, '0.5', 'weekly');
            }
        }
        $add($base . '/skin-quiz/', null, '0.5', 'monthly');
        // /brands/ 301s to /korean-skincare-brands/ (BrandController::legacyIndex,
        // and the owner confirmed the long address is the live one). Submitting
        // the redirect asked Google to fetch a URL it is then sent away from --
        // exactly the defect the /shop -> /shop/ entry above was corrected for,
        // one line below where it was corrected.
        $add($base . '/korean-skincare-brands/', null, '0.5', 'weekly');
        $add($base . '/skincare-guide/', null, '0.6', 'weekly');

        /*
         * The curated listings — /new-in/, /best-sellers/, /super-sale/ and
         * /everything-under-54-aed/.
         *
         * ▲ ASKED OF THE ROUTER, NOT WRITTEN OUT. This was a literal list of
         * four path strings, and `docs/SEO-BUILD-PLAN.md` Part II item 9 is
         * about exactly that: adding a fifth curated listing means editing four
         * hardcoded lists that must agree, and "miss the last one and the page
         * works perfectly and never enters the sitemap — a silent failure that
         * no screenshot catches."
         *
         * The plan asked for a TEST that the lists agree. A test would have
         * caught the drift; asking the router removes the second list, so
         * there is nothing left to drift. That is the same move the concern
         * block below already makes — "the same question the router asks,
         * asked of the same class" — and this block was the one place on this
         * page still doing it the other way.
         *
         * THE ROUTE PATH AND THE COLLECTION KEY ARE DIFFERENT STRINGS and this
         * is why the naive version of that test would have been wrong:
         * CollectionController's internal key `under-54` is served at
         * `/everything-under-54-aed`. The router knows both — the URI and the
         * `key` default — so it is the only source that cannot be half right.
         *
         * Registration order is preserved, so the bytes of /sitemap.xml are
         * what they were.
         */
        foreach (self::curatedListingPaths() as $path) {
            $add($base . $path, null, '0.6', 'daily');
        }

        /*
         * The concern-led listings — /concern/acne/ and any other concern the
         * owner has both written copy for and tagged enough products for.
         *
         * THE SAME QUESTION THE ROUTER ASKS, ASKED OF THE SAME CLASS.
         * App\Support\ConcernCollections::live() is what
         * CollectionController::concern() consults before it 404s, so this
         * cannot advertise a URL the site then refuses -- a sitemap entry that
         * 404s is a Search Console error, and the entry two blocks up was
         * already corrected once for advertising a redirect.
         *
         * SO THIS LIST IS USUALLY EMPTY, and that is correct: until the owner
         * has tagged MIN_PRODUCTS live products for a concern the page does not
         * exist, and the sitemap says so by not mentioning it. Nothing about
         * /sitemap.xml changes by a byte on a shop that has not tagged
         * anything.
         *
         * `weekly` rather than the `daily` above: these listings change when an
         * operator tags a product, not when the catalogue turns over.
         */
        foreach (ConcernCollections::live() as $concern) {
            $add($base . ConcernCollections::path($concern), null, '0.6', 'weekly');
        }

        // The content pages behind the footer links. Only the seven slugs
        // routes/web.php actually routes, and only where the row is published:
        // PageController::show() 404s anything else, and a sitemap entry that
        // 404s is a Search Console error.
        if (Schema::hasTable('pages')) {
            // ▲ ASKED OF THE ROUTER, for the same reason as the curated
            // listings above: this was the seven slugs written out, and an
            // eighth routed content page would have rendered perfectly and
            // never entered this file. Each of these routes carries its slug as
            // a route default, which is where PageController::show() reads it
            // from, so the router holds the same string the controller does.
            $routedPaths = self::routedPagePaths();
            $routed = array_keys($routedPaths);

            /*
             * ▲ `seo` RIDES ALONG, BECAUSE A NOINDEXED PAGE WAS STILL SUBMITTED.
             *
             * isNoindex() below this block already existed and its own comment
             * says the rule: "a page that sets noindex must drop out of this
             * file whichever table it lives in. Google reports the pair 'page
             * says noindex, sitemap says crawl me' as an error against the
             * property". It was applied to products, categories and brands and
             * not to `pages`, which was the one table whose column this query
             * did not even select.
             *
             * Measured before this line existed: `pages.seo.noindex` set on the
             * `faqs` row, and /sitemap.xml went on carrying /faqs/ — while
             * PageController::show() went on serving "index, follow" on it,
             * because that half was broken too. Two documents agreeing with each
             * other and both disagreeing with the owner.
             *
             * One extra column on a query that was already running, so the
             * sitemap's measured query count does not move.
             */
            $pages = DB::table('pages')
                ->select('slug', 'updated_at', 'seo')
                ->whereIn('slug', $routed)
                ->where('status', 'published')
                ->get();

            foreach ($pages as $page) {
                if (self::isNoindex($page->seo ?? null)) {
                    continue;
                }

                // The ROUTER's address for this row, not the slug spelled into
                // a URL — see routedPagePaths(). Identical for all seven today.
                $add($base . $routedPaths[$page->slug], $page->updated_at ?? null, '0.4', 'monthly');
            }
        }

        // Products.
        //
        // This filtered on status 'active'. Products use 'publish' -- see
        // Product::scopeVisible() -- so the condition matched nothing and the
        // sitemap has been shipping with zero products in it. is_visible was
        // not checked either, which would have leaked hidden products the
        // moment the status string was corrected on its own.
        //
        // Trailing slash matters: Product::url() and the URL contract both use
        // /product/{slug}/, and /product/{slug} 301s to it. Without the slash
        // every entry here pointed at a redirect rather than the canonical URL.
        // Which brands have a live product, collected below from the product
        // query this file already runs. See the brand block further down for
        // why it is gathered here rather than asked for separately.
        $liveBrandIds = [];

        if (Schema::hasTable('products')) {
            $q = DB::table('products')->select('slug', 'updated_at');

            // brand_id rides along on the query that is happening anyway. It
            // costs nothing and it is what makes the brand block below a
            // single extra SELECT rather than a second full pass over
            // products with its own column probes.
            $hasBrand = Schema::hasColumn('products', 'brand_id');

            if ($hasBrand) {
                $q->addSelect('brand_id');
            }

            /*
             * One helper rather than three hand-written clauses, because a
             * fourth condition arrived and this file did not get it.
             *
             * Scheduled publishing added products.published_at, and
             * Product::scopeVisible() honours it — so a product scheduled for
             * next week is correctly absent from the shop and its own URL
             * 404s. This sitemap is built from a raw query builder, which no
             * model scope can reach, so it went on listing that URL. A sitemap
             * entry that 404s is a soft 404 in Search Console, which is the
             * exact opposite of what scheduling a launch is for.
             *
             * ProductVisibility::raw() is the same set of conditions the scope
             * applies, column-guarded the same way the code it replaces was,
             * so it is safe to run before the migration that adds the column.
             */
            \App\Support\ProductVisibility::raw($q, '');

            // A product carrying noindex in its per-product SEO overrides is
            // one the owner has said should not be in the index. The page
            // already emits "noindex, nofollow" for it; listing the same URL in
            // the sitemap asks Google to come and crawl a page whose only
            // instruction is to go away. Every such fetch is crawl budget taken
            // off a product that does want to rank, and Search Console reports
            // the pair as "Submitted URL marked noindex".
            //
            // The column is json, so the flag is read in PHP rather than
            // matched in SQL: MySQL and SQLite disagree about json_extract and
            // about how `true` comes back out of it, and this file runs on
            // both.
            $hasSeo = Schema::hasColumn('products', 'seo');

            if ($hasSeo) {
                $q->addSelect('seo');
            }

            // Two more columns on the query that is already running, and only
            // when they will be used. Both are on `products` from the first
            // migration, so neither needs a schema probe — the probes above
            // exist for columns that arrived later.
            if ($withImages) {
                $q->addSelect('image', 'images');
            }

            foreach ($q->get() as $p) {
                if (empty($p->slug)) continue;

                // Before the noindex skip: a brand carrying one product the
                // owner has excluded from the index still has a live product
                // and a page worth listing. The brand page is not the product
                // page, and only the product was excluded.
                if ($hasBrand && !empty($p->brand_id)) {
                    $liveBrandIds[(int) $p->brand_id] = true;
                }

                if ($hasSeo && self::isNoindex($p->seo ?? null)) continue;
                $add(
                    $base . '/product/' . $p->slug . '/',
                    $p->updated_at ?? null,
                    '0.8',
                    'weekly',
                    $withImages ? $this->productImages($p, $base) : []
                );
            }
        }

        // Categories.
        //
        // These were listed under /category/{slug}, which has never been a
        // route -- the real one is /product-category/{path}/, and Category::url()
        // builds it from the full nested path, not the bare slug. Every
        // category URL in the sitemap was a 404.
        if (Schema::hasTable('categories')) {
            /*
             * EVERY COLUMN, AND ONE FEWER QUERY THAN THE NAMED LIST COST.
             *
             * This read two optional columns — `path`, added after the table,
             * and now `seo` — and the only way to name an optional column in a
             * SELECT without a 500 on an un-migrated install is to ask the
             * schema first. On SQLite a Schema::hasColumn() is a PRAGMA that
             * DB::listen sees, and this file is on a query budget whose own
             * comment records that twelve of its eighteen queries were schema
             * introspection. Two probes to read two columns is the wrong trade
             * for a table of tens of rows: `select *` needs none, and it is one
             * fewer query than the version before this change.
             *
             * `products` below deliberately keeps its probes. That table is the
             * catalogue — six hundred rows on this store — and selecting every
             * column of it to avoid two PRAGMAs is not the same bargain.
             */
            foreach (DB::table('categories')->get() as $c) {
                $path = trim((string) ($c->path ?? ''), '/') ?: $c->slug;

                if (empty($path)) continue;

                // A category whose SEO overrides ask for noindex is not a URL
                // to submit. ShopController puts "noindex, nofollow" on the
                // archive itself; this is the other half of the same decision.
                if (self::isNoindex($c->seo ?? null)) continue;

                $add($base . '/product-category/' . $path . '/', $c->updated_at ?? null, '0.6', 'weekly');
            }
        }

        // Brand landing pages.
        //
        // /korean-skincare-brands/{slug}/ has been a real, indexable page since
        // Phase 9 and not one of them has ever been in the sitemap -- the file
        // listed the brand INDEX (at its redirecting address, above) and
        // nothing else, so on a catalogue of ninety-three brands the only crawl
        // path to a brand page was the A-Z listing and the mega menu.
        //
        // Only brands that actually carry a live product. A brand page with an
        // empty grid is a thin page, and asking Google to fetch a set of them
        // is the same crawl-budget tax the noindex-product skip above avoids.
        // ProductVisibility::raw() is the same predicate BrandController::index
        // counts with, so the sitemap and the page agree about what is live.
        // The set comes out of the product loop above rather than from a
        // second visibility-filtered pass over products: that pass would
        // re-run ProductVisibility::raw(), whose four Schema::hasColumn()
        // probes are each a round trip on both engines, and this file has a
        // query budget (tests/Feature/StorefrontQueryBudgetTest). Collecting
        // brand_id from the rows already fetched makes it one SELECT, and
        // makes "has a live product" true by construction rather than by a
        // second predicate that could drift from the first.
        if ($liveBrandIds !== [] && Schema::hasTable('brands')) {
            // `select *` for the reason the category block above carries: it
            // reads `brands.seo`, which is optional, and asking the schema
            // whether it is there costs a query on a file that counts them.
            // Only brands with a live product are fetched, so this is a handful
            // of narrow rows either way.
            $brands = DB::table('brands')
                ->whereIn('id', array_keys($liveBrandIds))
                ->get();

            foreach ($brands as $b) {
                if (empty($b->slug)) {
                    continue;
                }

                // Same test the product loop above applies, for the same
                // reason. BrandController::seoCtx() publishes "noindex,
                // nofollow" on the landing page from this flag; listing the
                // URL here anyway is what Search Console reports as "Submitted
                // URL marked noindex".
                if (self::isNoindex($b->seo ?? null)) {
                    continue;
                }

                $add($base . '/korean-skincare-brands/' . $b->slug . '/', $b->updated_at ?? null, '0.5', 'weekly');
            }
        }

        // Articles live at the site root since 2.60.109; /post/{slug} and
        // /skincare-guide/{slug}/ both 301 to /{slug}/, so only the canonical
        // form belongs in the sitemap.
        // as of 2.60.93, so the redirect target is listed directly.
        if (Schema::hasTable('posts')) {
            $q = DB::table('posts')->select('slug', 'updated_at');

            if (Schema::hasColumn('posts', 'status')) {
                $q->where('status', 'published');
            }

            foreach ($q->get() as $post) {
                if (empty($post->slug)) continue;
                $add($base . '/' . $post->slug . '/', $post->updated_at ?? null, '0.6', 'monthly');
            }
        }

        /*
         * ── ONE SITEMAP CARRYING xhtml:link ALTERNATES, NOT A SITEMAP INDEX ──
         *
         * The two shapes Google accepts are (a) one file in which each <url>
         * names every language of that page with <xhtml:link>, and (b) a
         * sitemap index pointing at one file per language. This is (a), and the
         * reasons are specific to this shop rather than general:
         *
         * 1. A CLUSTER HAS TO BE COMPLETE AND RECIPROCAL, and shape (a) makes
         *    that true by construction. Every entry below is built from ONE
         *    call to Locale::alternatePaths() — the same call layouts and
         *    App\Support\Seo make for the <head> — so the sitemap cannot
         *    disagree with the page about what the page's alternates are. Two
         *    files built in two passes can drift, and a cluster that does not
         *    reciprocate is dropped in full rather than half-honoured.
         *
         * 2. THE SWITCH HAS TO BE ABLE TO GO BACK OFF. Arabic is a setting
         *    (Locale::enabled) precisely so it needs no release to flip. Under
         *    shape (b) flipping it changes which FILES exist: /sitemap-ar.xml
         *    appears and disappears, and Search Console keeps fetching a child
         *    sitemap it has already seen and starts reporting 404s on it. Here
         *    the address never changes, and with Arabic off this method emits
         *    byte-for-byte the file it emits today — verified by diffing the
         *    fetched /sitemap.xml against the tip's — no index wrapper, no
         *    xmlns:xhtml, no /ar anywhere.
         *
         * 3. THERE IS NO SIZE ARGUMENT FOR SPLITTING. The limits are 50,000
         *    URLs and 50MB uncompressed. This catalogue is 671 products; two
         *    languages of the whole sitemap is about 1,400 <url> elements. An
         *    index exists to get under a ceiling that is two orders of
         *    magnitude away.
         *
         * 4. A SECOND SITEMAP ADDRESS IS A SECOND THING TO GET WRONG on a host
         *    where a new route does not exist until a migration has cleared the
         *    compiled route table. Locale::localisable() already refuses to put
         *    /ar in front of anything with a dot in it, for exactly this
         *    reason: a machine-facing document has one canonical address.
         *
         * The xhtml namespace is declared only when there is something to put
         * in it, so the English-only file is unchanged down to the root element.
         */
        $bilingual = count(Locale::enabledCodes()) > 1;

        /*
         * The image namespace is declared only when an entry actually carries
         * an image, for exactly the reason the xhtml one is declared only when
         * there is a second language: an unused namespace on the root element
         * is a byte change to a file crawlers have already fetched, in exchange
         * for nothing. With the switch off, or with a catalogue that has no
         * pictures at all, the root element is the one that ships today.
         */
        $anyImages = false;

        foreach ($urls as $u) {
            if (! empty($u['images'])) {
                $anyImages = true;
                break;
            }
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
              . ($bilingual ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '')
              . ($anyImages ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '')
              . '>' . "\n";

        foreach ($urls as $u) {
            foreach ($this->cluster((string) $u['loc'], $base) as $entry) {
                $xml .= '  <url><loc>' . htmlspecialchars($entry['loc'], ENT_XML1) . '</loc>';

                // Immediately after <loc>, which is where Google's own
                // documented example puts them.
                /*
                 * ENT_QUOTES, AND THESE TWO NEED IT WHERE <loc> ABOVE DOES NOT.
                 *
                 * htmlspecialchars($v, ENT_XML1) does NOT escape a double
                 * quote: passing a flag REPLACES the default set rather than
                 * adding to it, and ENT_QUOTES is in the default. Inside
                 * element text — <loc>, <image:loc> — a bare `"` is legal XML
                 * and the document still parses, so that call is correct as it
                 * stands. Here the value lands inside an ATTRIBUTE, where a
                 * bare `"` closes it: one quote in a URL and this element
                 * becomes malformed, which costs the whole sitemap rather than
                 * one entry, because a parser that fails rejects the document.
                 *
                 * No URL the shop builds today carries one, so this emits the
                 * same bytes it emits now. It is written this way because the
                 * value is a URL and the rule for a URL going into an
                 * attribute should not depend on nobody ever putting a quote
                 * in a slug.
                 */
                foreach ($entry['alternates'] as $hreflang => $href) {
                    $xml .= '<xhtml:link rel="alternate" hreflang="' . htmlspecialchars((string) $hreflang, ENT_QUOTES | ENT_XML1)
                          . '" href="' . htmlspecialchars($href, ENT_QUOTES | ENT_XML1) . '"/>';
                }

                if (!empty($u['lastmod'])) {
                    $d = @date('Y-m-d', strtotime((string) $u['lastmod']));
                    if ($d) $xml .= '<lastmod>' . $d . '</lastmod>';
                }
                $xml .= '<changefreq>' . $u['freq'] . '</changefreq>';
                $xml .= '<priority>' . $u['priority'] . '</priority>';

                /*
                 * THE SAME PICTURES ON EVERY LANGUAGE OF THE PAGE, which is
                 * correct: /shop/x/ and /ar/shop/x/ are the same product and
                 * the same photographs, and an image listed under only one of
                 * a cluster's URLs tells Google the other language has none.
                 */
                foreach ($u['images'] as $img) {
                    // ENT_QUOTES here too, for the same reason as the
                    // attributes above even though this one is element text:
                    // an image URL is the most operator-supplied value in this
                    // file, and one escaping rule for it is easier to keep
                    // right than two.
                    $xml .= '<image:image><image:loc>' . htmlspecialchars($img, ENT_QUOTES | ENT_XML1) . '</image:loc></image:image>';
                }

                $xml .= '</url>' . "\n";
            }
        }
        $xml .= '</urlset>';

        return $this->publiclyCacheable(
            $request,
            response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8')
        );
    }

    /**
     * A product row's pictures, absolute, de-duplicated, order preserved.
     *
     * THE FEATURED SHOT FIRST AND THEN THE GALLERY. Google treats the first
     * <image:image> under a <url> as the most representative one, and the
     * featured image is what the shop itself puts at the top of the product
     * page, so the two agree rather than disagreeing by accident of column
     * order.
     *
     * A VALUE THAT IS NOT A PICTURE IS DROPPED RATHER THAN PUBLISHED. The
     * `images` column is json written by the WooCommerce importer, the media
     * sideloader and the product editor, and a row that has been through all
     * three can hold a string, a null, or an object with the URL on a key
     * this never sees. Anything that is not a non-empty string is skipped, and
     * anything that is not http(s) after absolutising is skipped too — a
     * sitemap is a document a crawler parses strictly, and one malformed
     * <image:loc> is a reason to reject the entry around it.
     *
     * THE SCHEME CHECK IS NOT DECORATION. These values are operator-supplied
     * (an image URL is a text box on the product editor), and this file prints
     * them into XML that Google fetches. `javascript:` and `data:` are not
     * addresses a crawler should ever be handed, and the rule this project
     * already applies to a settings-supplied href applies to a row-supplied
     * one for the same reason.
     *
     * @return list<string>
     */
    private function productImages(object $p, string $base): array
    {
        $raw = $p->images ?? null;

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        $candidates = [$p->image ?? null];

        foreach (is_array($raw) ? $raw : [] as $one) {
            $candidates[] = $one;
        }

        $root = rtrim($base, '/');
        $out = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            // Root-relative is how this shop stores an uploaded image, so it is
            // the common case and not the exception.
            $abs = str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//')
                ? $root . $candidate
                : $candidate;

            $scheme = parse_url($abs, PHP_URL_SCHEME);

            if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
                continue;
            }

            if (! in_array($abs, $out, true)) {
                $out[] = $abs;
            }
        }

        return $out;
    }

    /**
     * One sitemap URL in, every language of it out, each carrying the whole set.
     *
     * With one language live this returns the entry exactly as it came in and
     * no alternates at all — the single place the bilingual sitemap decides to
     * be an English sitemap, and the reason nothing else in sitemap() needed to
     * learn about a second language.
     *
     * The deployment prefix is held aside before the locale segment is added
     * and put back after, because the two compose in one order only:
     * /kbb-upgrade/ar/shop/. site_url normally already carries the base path
     * (APP_URL does on the production host) in which case it is inside $base
     * and $prefix is '' — this handles the other spelling too rather than
     * assuming which one is configured, because the shop has shipped with both.
     *
     * A loc that is not under $base is handed straight back. Nothing builds one
     * today, and quietly putting /ar/ in front of another host's URL is the
     * kind of thing that should need a decision rather than happen.
     *
     * @return list<array{loc: string, alternates: array<string, string>}>
     */
    private function cluster(string $loc, string $base): array
    {
        $plain = [['loc' => $loc, 'alternates' => []]];

        $root = rtrim($base, '/');

        if ($root === '' || ! str_starts_with($loc, $root)) {
            return $plain;
        }

        $path = substr($loc, strlen($root));

        if ($path === '' || $path[0] !== '/') {
            return $plain;
        }

        $prefix = '';
        $basePath = Url::base();

        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $prefix = $basePath;
            $path = substr($path, strlen($basePath));
            $path = $path === '' ? '/' : $path;
        }

        $alternates = Locale::alternatePaths($path);

        if ($alternates === []) {
            return $plain;
        }

        $hrefs = [];

        foreach ($alternates as $code => $localePath) {
            $hrefs[$code] = $root . $prefix . $localePath;
        }

        // x-default last, and pointing at the default language: where a reader
        // whose browser asks for neither should land.
        $hrefs['x-default'] = $hrefs[Locale::DEFAULT] ?? $loc;

        $out = [];

        foreach ($alternates as $code => $localePath) {
            $out[] = ['loc' => $hrefs[$code], 'alternates' => $hrefs];
        }

        return $out;
    }

    /**
     * GET /{key}.txt — IndexNow key-file verification. The route pattern
     * only matches strings shaped like a real IndexNow key (8-128 chars,
     * alphanumeric/dash), so this doesn't swallow every other .txt request
     * on the site — and still checks it's the actual current key, not just
     * a plausible-looking one, before serving anything.
     */
    public function indexNowKeyFile(string $key)
    {
        if ($key !== \App\Services\Seo\IndexNow::key()) {
            abort(404);
        }

        return response($key, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * GET /llms.txt — a plain-text summary for AI crawlers/agents, not a
     * sitemap replacement.
     *
     * TWO OF THE THREE LINES IN THIS FILE WERE WRONG, and this is the one
     * crawl surface nobody looks at.
     *
     * The description is `seo_default_description`, which until the migration
     * 2026_11_07_000000_honest_default_meta_description rewrote it ended
     * "100% genuine, next-day delivery, glowing skin guaranteed" — a delivery
     * window the shop's own DeliveryLine contradicts and a guarantee about a
     * cosmetic outcome. It is quoted here verbatim to anything that reads this
     * file, which is the audience least able to check it against the shop.
     *
     * Both "key pages" named a URL the site does not serve at that address.
     * `/blog` is a 301 to /skincare-guide/ (routes/web.php), and `/shop` is
     * the unslashed form the shop canonicalises away from — so the two links
     * this file offers were a redirect and a redirect. That is the same defect
     * sitemap() above was corrected for twice, in a file that shares its
     * helper and sits forty lines away. tests/Feature/MachineFacingClaimsTest
     * now walks these links the way SeoCrawlSurfaceTest walks the sitemap's.
     */
    public function llms(Request $request)
    {
        $s = SeoSettings::map();

        if (SeoSettings::from($s, 'llms_enabled') !== '1') {
            return response('Not enabled', 404);
        }

        $base = $this->base();
        $name = SeoSettings::firstFilled(
            $s['seo_site_name'] ?? null,
            $s['store_name'] ?? null,
            (string) config('app.name'),
            'K-Beauty Bliss'
        );
        $desc = SeoSettings::from($s, 'seo_default_description', '');

        $lines = [
            "# {$name}",
            '',
            $desc !== '' ? $desc : 'Online store.',
            '',
            '## Key pages',
            // The address each page actually answers on, trailing slash and
            // all — the same form the sitemap submits and the canonical
            // declares, never the one the site redirects from.
            "- [Shop]({$base}/shop/)",
            "- [Journal]({$base}/skincare-guide/)",
        ];

        /*
         * ── WHICH LANGUAGES THIS SHOP IS PUBLISHED IN ────────────────────────
         *
         * The audience for this file is the audience least able to check it
         * against the shop, which is the reason the comment on llms() above
         * exists at all. An agent handed only the English addresses will report
         * that this shop has no Arabic, while every page of it is carrying
         * hreflang="ar" and the sitemap is naming 671 Arabic URLs.
         *
         * Only once there is a second language live — with Arabic off,
         * Locale::enabledCodes() is ['en'], this block emits nothing, and the
         * file is byte-for-byte what it is today. The addresses are built
         * through Locale::withSegment() rather than written out, so a third
         * language is a row in Locale::LOCALES and not an edit here.
         */
        $live = Locale::enabledCodes();

        if (count($live) > 1) {
            $lines[] = '';
            $lines[] = '## Languages';

            foreach ($live as $code) {
                $meta = Locale::LOCALES[$code];
                $label = $meta['native'] === $meta['name']
                    ? $meta['name']
                    : $meta['name'] . ' (' . $meta['native'] . ')';

                $lines[] = "- {$label} — `{$code}`"
                    . ($code === Locale::DEFAULT ? ', served unprefixed' : ', served under /' . $meta['segment'] . '/')
                    // $base already carries the deployment prefix, exactly as
                    // the Key pages block above assumes — so these are the bare
                    // localised paths, not Url::raw(), which would print
                    // /kbb-upgrade twice.
                    . ': [Shop](' . $base . Locale::withSegment('/shop/', $code) . ')'
                    . ', [Journal](' . $base . Locale::withSegment('/skincare-guide/', $code) . ')';
            }
        }

        return $this->publiclyCacheable(
            $request,
            response(implode("\n", $lines) . "\n", 200)
                ->header('Content-Type', 'text/plain; charset=UTF-8')
        );
    }


    public function robots(Request $request)
    {
        /*
         * ── A PRIVATE INSTALL, AND THE TRAP THAT MAKES `Disallow: /` WRONG ──
         *
         * The obvious robots.txt for a staging site is:
         *
         *     User-agent: *
         *     Disallow: /
         *
         * and it is the wrong answer. Disallow forbids CRAWLING, not indexing.
         * A URL that Google may not crawl is a URL whose noindex it can never
         * read -- this file's own comment below says exactly that about the
         * locale paths -- so a staging address someone links to gets listed
         * from the link alone, title-less and permanent, and the noindex that
         * would have removed it is behind the door we just shut.
         *
         * So a private install does the opposite and it is deliberate: invite
         * the crawl, and let every page, image and XML file it reaches answer
         * with `X-Robots-Tag: noindex, nofollow, noarchive` from CanonicalHost.
         * Read, understood, dropped -- and dropped from the index it is already
         * in, which `Disallow` cannot do either.
         *
         * ▲ THIS IS THE SECOND-BEST ANSWER. The best one is HTTP Basic Auth on
         * the staging host, which no crawler gets past at all and which also
         * stops a competitor reading next month's prices. It is a hosting
         * control, not an application one -- cPanel calls it Directory Privacy
         * -- so this application cannot set it for you and says so on the
         * Settings screen instead. With auth on, this file is never fetched and
         * none of the above matters.
         *
         * The custom override below is checked AFTER this, so a private install
         * cannot be given an indexable robots.txt by a value copied from
         * production's settings table.
         */
        if (\App\Support\SiteHost::isPrivate()) {
            $body = "# This install is private. Every response here carries\n"
                ."# X-Robots-Tag: noindex, nofollow, noarchive.\n"
                ."#\n"
                ."# Crawling is deliberately ALLOWED so that header can be read:\n"
                ."# a blocked URL is one whose noindex a crawler never sees, and\n"
                ."# it stays in the index on the strength of a single link.\n"
                ."User-agent: *\n"
                ."Disallow:\n";

            return $this->publiclyCacheable(
                $request,
                response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8')
            );
        }

        $s = SeoSettings::map();
        $custom = SeoSettings::from($s, 'robots_txt', '');
        if ($custom !== '') {
            return $this->publiclyCacheable(
                $request,
                response($custom, 200)->header('Content-Type', 'text/plain; charset=UTF-8')
            );
        }
        $base = $this->base();

        /*
         * ── EVERY PATH THROUGH Url::raw(), AND EVERY PRIVATE ONE ONCE PER
         *    LANGUAGE ──────────────────────────────────────────────────────
         *
         * A robots.txt rule is matched against the path as the crawler sees it
         * in the address bar, which is the deployment prefix plus the locale
         * segment plus the path. This file printed neither.
         *
         * The locale half is the leak. With Arabic switched on, /ar/checkout,
         * /ar/cart, /ar/my-account and /ar/track-my-order are real, served,
         * 200-answering addresses that this file said nothing about — so the
         * account area was Disallowed in English and crawlable in Arabic, which
         * is the same shape of defect CLAUDE.md records this shop shipping
         * before and the reason Indexability exists at all. The pages
         * themselves do emit noindex under /ar (Indexability::isPrivate now
         * strips the locale segment before matching), but a Disallow and a
         * noindex are not the same instruction and the two files have to agree:
         * a URL Google may not crawl is a URL whose noindex it can never read.
         *
         * The base-path half is smaller and was wrong the same way. On the host
         * that serves this app from /kbb-upgrade, "Disallow: /checkout" names a
         * path at the DOMAIN root that this application does not serve, so the
         * rule protected nothing. Url::raw() — raw, not to(), because each of
         * these is written out once per language explicitly — puts the prefix
         * on. With no base path configured it returns its argument, so the
         * bytes on the default path do not move.
         *
         * /admin stays a decoy: the real admin path is a setting, and naming it
         * here would publish the one thing keeping it quiet. It is not
         * localised either — Locale::UNLOCALISED_ROOTS keeps the back office to
         * one address.
         */
        $body = "User-agent: *\nAllow: /\n";

        // The back office and the admin API have exactly one address each:
        // Locale::UNLOCALISED_ROOTS lists 'admin-api', and the configured admin
        // path is excluded by Locale::localisable(), so neither answers under
        // /ar and a prefixed rule here would name nothing.
        foreach (['/admin', '/admin-api'] as $path) {
            $body .= 'Disallow: ' . Url::raw($path) . "\n";
        }

        /*
         * /api IS LOCALISED AND IT SHOULD NOT BE, but that is not this file's
         * call to make.
         *
         * Locale::UNLOCALISED_ROOTS lists 'admin-api' and does not list 'api',
         * so the middleware strips the prefix off /ar/api/products and the
         * public API answers 200 there — verified against the running app. That
         * is a second crawlable address for every endpoint in it. Whether the
         * router should serve it at all is a question about that constant, and
         * the constant belongs to the bilingual foundation; what this file can
         * do is stop asking crawlers to spend budget on the copy. Listed here
         * rather than silently omitted, so the rule is not quietly wrong the
         * day the constant changes either way.
         *
         * Then the thin or per-visitor pages: a cart, an account area and a
         * wishlist are different for every visitor and useless in a result.
         * Every crawl of them is budget not spent on a product.
         */
        foreach (array_merge(['/api'], self::ROBOTS_PRIVATE) as $path) {
            foreach (Locale::enabledCodes() as $code) {
                $body .= 'Disallow: ' . Url::raw(Locale::withSegment($path, $code)) . "\n";
            }
        }

        $body .= "\nSitemap: {$base}/sitemap.xml\n";

        return $this->publiclyCacheable(
            $request,
            response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8')
        );
    }
}
