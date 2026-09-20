<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Seo\SeoSettings;
use App\Support\Locale;
use App\Support\Url;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeoFilesController extends Controller
{
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
     */
    private function base(): string
    {
        $s = SeoSettings::map();

        return rtrim(SeoSettings::firstFilled(
            $s['site_url'] ?? null,
            (string) config('app.url'),
            url('/')
        ), '/');
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
    public function sitemap()
    {
        $s = SeoSettings::map();
        if (SeoSettings::from($s, 'sitemap_enabled') === '0') {
            return response('Sitemap disabled', 404);
        }

        $base = $this->base();
        $urls = [];
        $add = function ($loc, $lastmod = null, $priority = '0.6', $freq = 'weekly') use (&$urls) {
            $urls[] = compact('loc', 'lastmod', 'priority', 'freq');
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

        // The curated listings. Four real, indexable pages, linked from the
        // site header, that no sitemap has ever mentioned. The keys are the
        // route paths, not CollectionController's internal keys -- 'under-54'
        // is served at /everything-under-54-aed.
        foreach (['new-in', 'best-sellers', 'super-sale', 'everything-under-54-aed'] as $collection) {
            $add($base . '/' . $collection . '/', null, '0.6', 'daily');
        }

        // The content pages behind the footer links. Only the seven slugs
        // routes/web.php actually routes, and only where the row is published:
        // PageController::show() 404s anything else, and a sitemap entry that
        // 404s is a Search Console error.
        if (Schema::hasTable('pages')) {
            $routed = [
                'about', 'contact-us', 'delivery', 'faqs',
                'privacy-policy', 'refund_returns', 'terms-and-conditions',
            ];

            $pages = DB::table('pages')
                ->select('slug', 'updated_at')
                ->whereIn('slug', $routed)
                ->where('status', 'published')
                ->get();

            foreach ($pages as $page) {
                $add($base . '/' . $page->slug . '/', $page->updated_at ?? null, '0.4', 'monthly');
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
                $add($base . '/product/' . $p->slug . '/', $p->updated_at ?? null, '0.8', 'weekly');
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

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
              . ($bilingual ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '')
              . '>' . "\n";

        foreach ($urls as $u) {
            foreach ($this->cluster((string) $u['loc'], $base) as $entry) {
                $xml .= '  <url><loc>' . htmlspecialchars($entry['loc'], ENT_XML1) . '</loc>';

                // Immediately after <loc>, which is where Google's own
                // documented example puts them.
                foreach ($entry['alternates'] as $hreflang => $href) {
                    $xml .= '<xhtml:link rel="alternate" hreflang="' . htmlspecialchars((string) $hreflang, ENT_XML1)
                          . '" href="' . htmlspecialchars($href, ENT_XML1) . '"/>';
                }

                if (!empty($u['lastmod'])) {
                    $d = @date('Y-m-d', strtotime((string) $u['lastmod']));
                    if ($d) $xml .= '<lastmod>' . $d . '</lastmod>';
                }
                $xml .= '<changefreq>' . $u['freq'] . '</changefreq>';
                $xml .= '<priority>' . $u['priority'] . '</priority></url>' . "\n";
            }
        }
        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
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
    public function llms()
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

        return response(implode("\n", $lines) . "\n", 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }


    public function robots()
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

            return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $s = SeoSettings::map();
        $custom = SeoSettings::from($s, 'robots_txt', '');
        if ($custom !== '') {
            return response($custom, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
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

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
