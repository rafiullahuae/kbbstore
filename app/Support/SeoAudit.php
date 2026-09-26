<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Seo\SeoSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The indexable surface, scanned for the defects that cost rankings.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS WHEN `Catalogue Audit` ALREADY DOES
 * ---------------------------------------------------------------------------
 *
 * Admin\CatalogueAuditApiController scans PRODUCTS for THREE things: a missing
 * meta description, a missing image, and a short description too thin to build
 * a fallback from. That is a good screen and it is not this one. It does not
 * look at categories, brands, articles or pages — which on this shop is 93
 * brand landing pages, the whole category tree and the journal, every one of
 * them an indexable URL that the sitemap submits to Google — and it cannot see
 * the two defect classes that actually suppress a result rather than weaken it:
 *
 *   1. TWO URLS CLAIMING THE SAME TITLE. Google picks one and drops the other,
 *      and Search Console files it under "Duplicate without user-selected
 *      canonical". A per-row audit cannot see this by construction: it is a
 *      property of the SET, not of any row in it. This is the single most
 *      common way a catalogue of near-identical products loses pages from the
 *      index, and nothing in this application has ever looked for it.
 *
 *   2. A CANONICAL OVERRIDE POINTING SOMEWHERE ELSE. `seo.canonical` is a free
 *      text box on the product, category and brand editors. A row carrying
 *      `http://…` (downgraded scheme), `javascript:…` (not a URL at all) or
 *      another company's domain is a page instructing Google to credit that
 *      other address instead of this one. One wrong paste de-indexes a page
 *      silently and permanently, and no screen in the console shows it.
 *
 * ---------------------------------------------------------------------------
 * WHAT "EFFECTIVE TITLE" MEANS HERE, AND WHY IT IS NOT Seo::titleFor()
 * ---------------------------------------------------------------------------
 *
 * Duplicates are compared on the row's OWN contribution to its title — the
 * per-row SEO override if one is set, otherwise the row's name — and NOT on
 * the fully templated string App\Support\Seo produces.
 *
 * That is deliberate and it is the more useful comparison. The title template
 * appends the same separator and the same site name to every page in the shop,
 * so it is a constant: it cannot make two identical names different, and it
 * cannot make two different names the same. Comparing the templated strings
 * would produce exactly the same pairs, having first rendered the SEO engine
 * once per row — turning a three-query scan into a catalogue-sized render.
 *
 * It also keeps this file off Seo::render()'s internals while that file is
 * being changed in another lane. See docs/SEO-BUILD-PLAN.md.
 *
 * ---------------------------------------------------------------------------
 * MEMORY
 * ---------------------------------------------------------------------------
 *
 * Products are read in chunks of CHUNK and only the columns below are
 * selected; nothing here loads an Eloquent model. What survives a chunk is a
 * count per distinct title, plus at most SAMPLES example rows per finding —
 * so the working set is bounded by the number of DISTINCT titles and not by
 * the number of products. On a catalogue where every product is uniquely named
 * (the healthy case) that is one small array entry per product and still far
 * less than the rows themselves; on a catalogue with a duplicate problem it is
 * smaller still, which is the direction that matters.
 *
 * Every table is probed with Schema::hasTable() first, for the same reason
 * SeoFilesController does it: a half-migrated install must produce a short
 * report, not a 500.
 */
final class SeoAudit
{
    /** Rows per chunk on the products pass. */
    public const CHUNK = 500;

    /** Example rows kept per finding. The screen shows a sample, not a dump. */
    public const SAMPLES = 25;

    /**
     * Google truncates a result title at roughly 600 CSS pixels. Characters are
     * the honest proxy a server can measure — see the note in the build plan on
     * why this is NOT measured in a browser (rule 4: no JavaScript that
     * measures layout).
     */
    public const TITLE_MAX = 60;
    public const TITLE_MIN = 15;

    /** Under this many words a description is not a description. */
    public const DESC_MIN_WORDS = 8;

    /**
     * The whole report.
     *
     * @return array{
     *     scanned: array<string,int>,
     *     total: int,
     *     findings: array<string, array{label: string, why: string, count: int, samples: list<array<string,mixed>>}>,
     *     verdict: string
     * }
     */
    /**
     * Findings that describe an opportunity rather than a fault, and so are
     * left out of the one-line verdict. See verdict() for the argument.
     */
    /*
     * PUBLIC, at Lane S7's request and for its reason. The Overview screen ranks
     * findings by what each one costs the owner, and its "opportunity" band is
     * this same judgement made a second time. With the constant private, the
     * only thing keeping the two lists equal was a comment asking a future
     * reader not to let them drift; SeoOverviewScreenTest now pins them equal.
     * Read-only from outside -- nothing assigns to it.
     */
    public const ADVISORY = ['product_no_image_alt', 'legacy_url_no_redirect'];

    public static function run(): array
    {
        $scanned = [];

        // title (normalised) => ['count' => int, 'rows' => list of rows]
        $titles = [];
        $descriptions = [];

        $findings = self::emptyFindings();

        self::scanProducts($scanned, $titles, $descriptions, $findings);
        self::scanTaxonomy('categories', 'Category', $scanned, $titles, $descriptions, $findings);
        self::scanTaxonomy('brands', 'Brand', $scanned, $titles, $descriptions, $findings);
        self::scanPosts($scanned, $titles, $descriptions, $findings);
        self::scanPages($scanned, $titles, $descriptions, $findings);

        /*
         * NO $scanned ENTRY, AND THAT IS THE POINT.
         *
         * $total is array_sum($scanned) and the verdict line prints it. These
         * fifteen are addresses this shop does NOT serve -- they are not part
         * of the indexable surface and counting them would inflate the one
         * number on the screen an owner reads first. The finding is reported;
         * the total stays byte-identical to what it was before this check
         * existed.
         */
        self::scanLegacyAddresses($findings);

        /*
         * NO $scanned ENTRY EITHER, and for a second reason on top of the one
         * above: this finding is about the SHOP, not about a URL. It counts at
         * most one, and adding it to $scanned would make "indexable URLs
         * scanned" include a thing that is not a URL.
         */
        self::scanBusinessType($findings);

        self::collectDuplicates($titles, $findings, 'duplicate_title');
        self::collectDuplicates($descriptions, $findings, 'duplicate_description');

        $total = array_sum($scanned);

        // Cap the samples last, so a finding counts everything it found and
        // shows only what fits. Counting and showing are different jobs and
        // conflating them is how an audit quietly under-reports.
        foreach ($findings as $key => $finding) {
            $findings[$key]['count'] = $finding['count'];
            $findings[$key]['samples'] = array_slice($finding['samples'], 0, self::SAMPLES);
        }

        return [
            'scanned' => $scanned,
            'total' => $total,
            'findings' => $findings,
            'verdict' => self::verdict($total, $findings),
        ];
    }

    /**
     * The findings this audit can report, in the order the screen prints them.
     *
     * Declared up front rather than created on first hit, so a clean shop shows
     * "0" against every check instead of showing nothing at all. An audit that
     * renders an empty page when everything is fine is an audit nobody trusts,
     * because it looks identical to an audit that failed to run.
     */
    private static function emptyFindings(): array
    {
        $rows = [
            'duplicate_title' => [
                'Duplicate titles',
                'Two or more indexable URLs carry the same title. Google keeps one and drops the others — this is the most common way a catalogue loses pages from the index.',
            ],
            'duplicate_description' => [
                'Duplicate meta descriptions',
                'Several pages share one description. Not a ranking penalty, but Google rewrites the snippet itself, so the words you chose are not the words shown.',
            ],
            'bad_canonical' => [
                'Canonical points somewhere unsafe',
                'A per-row canonical override that is not an https URL, or that points at another host. This hands the ranking to that address instead of yours.',
            ],
            'title_too_long' => [
                'Title longer than ' . self::TITLE_MAX . ' characters',
                'Google truncates it in the result, so the end of the title is never read.',
            ],
            'title_too_short' => [
                'Title shorter than ' . self::TITLE_MIN . ' characters',
                'Too little to match a search against. Usually a one-word product or category name with nothing added.',
            ],
            'no_description' => [
                'No description at all',
                'Nothing for Google to print under the link, so it invents one from the page body.',
            ],
            'thin_description' => [
                'Description under ' . self::DESC_MIN_WORDS . ' words',
                'Present but too short to say anything. Counts as missing in practice.',
            ],
            'no_image' => [
                'No image',
                'No Product or CollectionPage image, nothing to share to social, and no entry in an image sitemap.',
            ],
            'product_no_image_alt' => [
                'Product image with no alt text',
                'Alt text is what tells Google Image Search what is IN a photograph, and beauty is a category people shop by looking. These products fall back to their own name, which is accurate and says nothing about the shot — "texture on the back of a hand" is the sentence that wins an image result. Store → Catalogue → Products → the product → Media; every gallery row has its own box.',
            ],
            'product_no_identifier' => [
                'Product has neither SKU nor GTIN',
                'Google Merchant listings and the free shopping surfaces match products on an identifier. Without one this product cannot appear there at all.',
            ],
            'product_no_category' => [
                'Product sits in no category',
                'Reachable only through /shop/ pagination, which is the deepest crawl path in the shop. Nothing links to it directly.',
            ],
            /*
             * LAST IN THE LIST ON PURPOSE. The screen draws the cards in the
             * order this array declares them, so a new key added anywhere but
             * the end would push every existing card down the page — a visible
             * change to a screen that already works, which is exactly what
             * CLAUDE.md rule 1 forbids.
             */
            'legacy_url_no_redirect' => [
                'Old shop address with no redirect',
                'The WooCommerce site published its category pages at the site root (/skincare/, /skincare-sets/) and Google still holds those addresses. This shop now forwards each of them to the matching category archive by itself, with no redirect row needed — so the ones listed here are the ones it cannot: there is no category, page or article in this shop answering to that name. Until there is, the address returns 404 and whatever ranking and links it had are dropped rather than passed on. Import the catalogue so the category exists, or decide where the address should go by hand at Store → SEO & Meta → Redirects & 404s.',
            ],
            /*
             * AFTER legacy_url_no_redirect FOR THE REASON THAT NOTE GIVES: the
             * screen draws these cards in declaration order, so a new key goes
             * at the END or every card already on the screen moves down.
             */
            'business_type_mismatch' => [
                'Business type and address disagree',
                'The Organization node says what kind of business this is, and the address settings say where it is. These two can be set so that they contradict each other, and nothing on any screen says so. Two shapes: a type of Store or Local business with no usable address is a shopfront this shop has not described — Google places a local business by its address, so there is nothing for it to place; and map coordinates filled in under a type of Organization or Online store are silently dropped, because coordinates and opening hours are properties of a place and neither of those two types is one. Fix either side at Store → Business Details → Business, or the type at Store → SEO & Meta → Organization.',
            ],
        ];

        $out = [];

        foreach ($rows as $key => [$label, $why]) {
            $out[$key] = ['label' => $label, 'why' => $why, 'count' => 0, 'samples' => []];
        }

        return $out;
    }

    /** Record a hit. Counting always; the row kept only while there is room. */
    private static function hit(array &$findings, string $key, array $row): void
    {
        $findings[$key]['count']++;

        if (count($findings[$key]['samples']) < self::SAMPLES) {
            $findings[$key]['samples'][] = $row;
        }
    }

    /**
     * The per-row SEO override, decoded.
     *
     * The column is json and this runs on both MySQL and SQLite, which disagree
     * about what comes back out of a json column — the same reason
     * SeoFilesController::isNoindex() decodes in PHP rather than matching in
     * SQL.
     */
    private static function override(mixed $seo): array
    {
        return self::jsonArray($seo);
    }

    /**
     * A json column as an array, whichever database handed it over.
     *
     * MySQL gives back a string and SQLite may give back a string or a decoded
     * value depending on the driver, so every json column in this file is
     * decoded here rather than matched in SQL.
     */
    private static function jsonArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * The checks that apply to every kind of row, run once per row.
     *
     * @param  array<string, array{count:int, rows:list<array<string,mixed>>}>  $titles
     */
    private static function common(
        array $row,
        array $override,
        string $title,
        string $description,
        array &$titles,
        array &$descriptions,
        array &$findings
    ): void {
        // ── Canonical override ────────────────────────────────────────────
        //
        // Checked BEFORE anything else because it is the only finding here
        // that can de-index a page on its own, and because the same rule the
        // storefront applies to a settings-supplied href applies to a
        // row-supplied one: scheme-check it before it is believed. An empty
        // box is not a finding — the page then self-canonicalises, which is
        // correct.
        $canonical = trim((string) ($override['canonical'] ?? ''));

        if ($canonical !== '' && ! self::canonicalIsSafe($canonical)) {
            self::hit($findings, 'bad_canonical', $row + ['detail' => $canonical]);
        }

        // ── Title ─────────────────────────────────────────────────────────
        $length = mb_strlen($title);

        if ($title !== '') {
            $key = mb_strtolower(trim($title));

            if (! isset($titles[$key])) {
                $titles[$key] = ['count' => 0, 'rows' => []];
            }

            $titles[$key]['count']++;

            if (count($titles[$key]['rows']) < self::SAMPLES) {
                $titles[$key]['rows'][] = $row;
            }

            if ($length > self::TITLE_MAX) {
                self::hit($findings, 'title_too_long', $row + ['detail' => $length . ' chars']);
            } elseif ($length < self::TITLE_MIN) {
                self::hit($findings, 'title_too_short', $row + ['detail' => $length . ' chars']);
            }
        }

        // ── Description ───────────────────────────────────────────────────
        $words = self::words($description);

        if ($words === 0) {
            self::hit($findings, 'no_description', $row);
        } elseif ($words < self::DESC_MIN_WORDS) {
            self::hit($findings, 'thin_description', $row + ['detail' => $words . ' words']);
        } else {
            // Only a description long enough to be real takes part in the
            // duplicate check. Fifty products sharing the empty string are
            // already reported once, as "no description"; reporting them a
            // second time as "duplicate description" is the same defect
            // counted twice and it buries the pages that genuinely do share a
            // written paragraph.
            $key = mb_strtolower(trim($description));

            if (! isset($descriptions[$key])) {
                $descriptions[$key] = ['count' => 0, 'rows' => []];
            }

            $descriptions[$key]['count']++;

            if (count($descriptions[$key]['rows']) < self::SAMPLES) {
                $descriptions[$key]['rows'][] = $row;
            }
        }
    }

    /**
     * Is this canonical override an address this shop may safely publish?
     *
     * https only, and a host only — not because http is rare, but because a
     * canonical is an instruction to move ranking authority somewhere, and the
     * two ways that goes wrong are a scheme that is not a web page at all
     * (`javascript:`, `data:`) and a downgrade that a mixed-content or HSTS
     * rule then breaks. A same-host http canonical on an https site is a
     * canonical pointing at a redirect, which Google drops.
     *
     * A ROOT-RELATIVE VALUE IS FINE. '/product/foo/' resolves against this
     * host by definition and is what an admin most often types. Only a value
     * that declares a scheme or a host is held to the https rule.
     */
    public static function canonicalIsSafe(string $canonical): bool
    {
        if (str_starts_with($canonical, '/') && ! str_starts_with($canonical, '//')) {
            return true;
        }

        $scheme = parse_url($canonical, PHP_URL_SCHEME);

        return is_string($scheme) && strtolower($scheme) === 'https';
    }

    /** Words in a description, tags stripped. Not str_word_count: that drops non-Latin scripts entirely, and this shop is bilingual. */
    public static function words(string $text): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        return $text === '' ? 0 : count(explode(' ', $text));
    }

    private static function scanProducts(array &$scanned, array &$titles, array &$descriptions, array &$findings): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $count = 0;

        $q = DB::table('products')
            ->select('id', 'slug', 'name', 'short_description', 'image', 'seo', 'sku', 'category_id');

        /*
         * GTIN, and the two columns the alt-text check needs, all arrived with
         * the product editor and are not on every install.
         *
         * ONE COLUMN LISTING, not three `Schema::hasColumn()` calls. Each of
         * those is a query, they run before a scan that is already the
         * heaviest read in the console, and they all ask the same database the
         * same question about the same table. Outside the chunk loop either
         * way -- a probe per row would be a probe per product.
         */
        $columns = Schema::getColumnListing('products');

        $hasGtin = in_array('gtin', $columns, true);

        if ($hasGtin) {
            $q->addSelect('gtin');
        }

        /*
         * `images` and `image_alts` arrived with the product editor too, so
         * they get the same treatment as `gtin`: probed ONCE, outside the chunk
         * loop, never per row. A schema probe is a query, and this method is
         * the one place in the audit that runs over the whole catalogue --
         * StorefrontQueryBudgetTest is a budget, not a suggestion.
         *
         * Both are json columns, and MySQL and SQLite disagree about what comes
         * back out of one, which is why they are decoded in PHP below rather
         * than matched in SQL -- the same reason override() exists.
         */
        $hasAlts = in_array('image_alts', $columns, true) && in_array('images', $columns, true);

        if ($hasAlts) {
            $q->addSelect('images', 'image_alts');
        }

        // The same visibility predicate the sitemap and the shop apply, so the
        // audit reports on exactly the set of URLs that are actually
        // indexable. Auditing a draft product is noise.
        ProductVisibility::raw($q, '');

        $q->orderBy('id')->chunk(self::CHUNK, function ($rows) use (
            &$scanned, &$titles, &$descriptions, &$findings, &$count, $hasGtin, $hasAlts
        ) {
            foreach ($rows as $p) {
                $override = self::override($p->seo ?? null);

                // A product the owner has marked noindex is off the index by
                // choice. It is not a defect and it is not counted.
                if (! empty($override['noindex'])) {
                    continue;
                }

                $count++;

                $row = [
                    'kind' => 'Product',
                    'name' => (string) ($p->name ?? ''),
                    'url' => '/product/' . ($p->slug ?? '') . '/',
                ];

                self::common(
                    $row,
                    $override,
                    trim((string) ($override['title'] ?? '')) ?: (string) ($p->name ?? ''),
                    trim((string) ($override['desc'] ?? '')) ?: (string) ($p->short_description ?? ''),
                    $titles,
                    $descriptions,
                    $findings
                );

                if (empty($p->image)) {
                    self::hit($findings, 'no_image', $row);
                }

                /*
                 * ALT TEXT, AND WHY THIS IS NOT THE SAME CHECK AS no_image.
                 *
                 * A product with no photograph at all is already counted
                 * above, and counting it twice would be noise -- there is no
                 * alt to write for a shot that does not exist. So this only
                 * looks at products that HAVE pictures.
                 *
                 * WHAT COUNTS AS MISSING. Product::altFor() never returns an
                 * empty string: with no stored alt it falls back to
                 * ProductTitle::alt(brand, name, ...), so every <img> on the
                 * storefront already carries something and NO PAGE IS BROKEN.
                 * What is missing is a sentence about the PHOTOGRAPH -- the
                 * thing Google Image Search matches on, and the reason this is
                 * a finding rather than a defect. The wording on the card says
                 * so rather than implying the shop is emitting empty alts,
                 * because an audit that overstates is an audit that gets
                 * ignored.
                 *
                 * The main image is checked as well as the gallery: it is not
                 * in `images` at all (Product::altFor()'s note records this),
                 * and it is the shot that appears in the search result.
                 */
                if ($hasAlts) {
                    $shots = [];

                    foreach (array_merge([$p->image], self::jsonArray($p->images ?? null)) as $shot) {
                        $shot = is_string($shot) ? trim($shot) : '';

                        // De-duplicated, because the featured shot is often
                        // repeated as the first gallery row and one photograph
                        // with one missing sentence is one problem.
                        if ($shot !== '' && ! in_array($shot, $shots, true)) {
                            $shots[] = $shot;
                        }
                    }

                    $alts = self::jsonArray($p->image_alts ?? null);

                    $without = 0;

                    foreach ($shots as $shot) {
                        if (trim((string) ($alts[$shot] ?? '')) === '') {
                            $without++;
                        }
                    }

                    if ($without > 0) {
                        self::hit($findings, 'product_no_image_alt', $row + [
                            // The screen already prints `detail` beside the
                            // name. "3 of 5" separates a product nobody has
                            // described from one that is nearly done.
                            'detail' => $without . ' of ' . count($shots) . ' shots',
                        ]);
                    }
                }

                $gtin = $hasGtin ? trim((string) ($p->gtin ?? '')) : '';

                if (trim((string) ($p->sku ?? '')) === '' && $gtin === '') {
                    self::hit($findings, 'product_no_identifier', $row);
                }

                if (empty($p->category_id)) {
                    self::hit($findings, 'product_no_category', $row);
                }
            }
        });

        $scanned['Products'] = $count;
    }

    /**
     * Categories and brands — the same shape, the same checks, two tables.
     *
     * Both are small (tens of rows on this shop) and both carry every column
     * this needs, so each is one `select *`. That is the same trade
     * SeoFilesController makes on these two tables and for the same reason:
     * naming an optional column costs a schema probe per column, and a probe
     * is a query.
     */
    private static function scanTaxonomy(
        string $table,
        string $kind,
        array &$scanned,
        array &$titles,
        array &$descriptions,
        array &$findings
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        $count = 0;

        foreach (DB::table($table)->get() as $r) {
            $override = self::override($r->seo ?? null);

            if (! empty($override['noindex'])) {
                continue;
            }

            $path = $kind === 'Category'
                ? '/product-category/' . trim((string) ($r->path ?? ''), '/') . '/'
                : '/korean-skincare-brands/' . ($r->slug ?? '') . '/';

            if ($kind === 'Category' && trim((string) ($r->path ?? ''), '/') === '') {
                $path = '/product-category/' . ($r->slug ?? '') . '/';
            }

            $count++;

            $row = ['kind' => $kind, 'name' => (string) ($r->name ?? ''), 'url' => $path];

            self::common(
                $row,
                $override,
                trim((string) ($override['title'] ?? '')) ?: (string) ($r->name ?? ''),
                trim((string) ($override['desc'] ?? '')) ?: (string) ($r->description ?? ''),
                $titles,
                $descriptions,
                $findings
            );

            // A brand or category landing page with no image is a grid with a
            // bare heading. It has no og:image either, so every share of it is
            // a grey box.
            if (empty($r->image) && empty($r->logo)) {
                self::hit($findings, 'no_image', $row);
            }
        }

        $scanned[$kind === 'Category' ? 'Categories' : 'Brands'] = $count;
    }

    private static function scanPosts(array &$scanned, array &$titles, array &$descriptions, array &$findings): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        $q = DB::table('posts');

        if (Schema::hasColumn('posts', 'status')) {
            $q->where('status', 'published');
        }

        $count = 0;

        foreach ($q->get() as $r) {
            $override = self::override($r->seo ?? null);

            if (! empty($override['noindex'])) {
                continue;
            }

            $count++;

            $row = ['kind' => 'Article', 'name' => (string) ($r->title ?? ''), 'url' => '/' . ($r->slug ?? '') . '/'];

            self::common(
                $row,
                $override,
                trim((string) ($override['title'] ?? '')) ?: (string) ($r->title ?? ''),
                trim((string) ($override['desc'] ?? '')) ?: (string) ($r->excerpt ?? ''),
                $titles,
                $descriptions,
                $findings
            );
        }

        $scanned['Articles'] = $count;
    }

    /**
     * The seven content pages routes/web.php actually routes.
     *
     * The same list SeoFilesController's sitemap uses, and for the same reason:
     * PageController::show() 404s anything else, so a row in `pages` that is
     * not one of these is not a URL and auditing it would report defects on a
     * page nobody can reach.
     */
    private static function scanPages(array &$scanned, array &$titles, array &$descriptions, array &$findings): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        $routed = ['about', 'contact-us', 'delivery', 'faqs', 'privacy-policy', 'refund_returns', 'terms-and-conditions'];

        $count = 0;

        foreach (DB::table('pages')->whereIn('slug', $routed)->where('status', 'published')->get() as $r) {
            $override = self::override($r->seo ?? null);

            if (! empty($override['noindex'])) {
                continue;
            }

            $count++;

            $row = ['kind' => 'Page', 'name' => (string) ($r->title ?? ''), 'url' => '/' . ($r->slug ?? '') . '/'];

            /*
             * `pages` HAS NO EXCERPT COLUMN. It carries `title`, `content` and
             * `seo`, and nothing else that could be a description — so the
             * per-row override is the ONLY place a content page's meta
             * description can come from, and a page without one genuinely has
             * none. This is checked against the migration rather than assumed:
             * reading $r->excerpt here would have been silently null on every
             * row and reported all seven pages as fine.
             */
            self::common(
                $row,
                $override,
                trim((string) ($override['title'] ?? '')) ?: (string) ($r->title ?? ''),
                trim((string) ($override['desc'] ?? '')),
                $titles,
                $descriptions,
                $findings
            );
        }

        $scanned['Pages'] = $count;
    }

    /**
     * Turn the title/description tallies into findings.
     *
     * A "duplicate" finding counts the PAGES INVOLVED, not the number of
     * clashing values: three products called "Sheet Mask" is three pages with a
     * problem, and reporting it as one is how an audit makes a real problem
     * look small.
     */
    /**
     * The addresses the OLD shop published that this one does not answer.
     *
     * ── WHAT THIS CATCHES, AND WHY NOTHING ELSE DOES ────────────────────────
     *
     * Every other finding in this file is a property of a row this shop
     * serves. This one is the opposite: it is about fifteen URLs that are in
     * Google's index right now, that this application deliberately does not
     * serve, and that therefore appear on no screen anywhere in the console.
     * A scan of the indexable surface cannot see them by construction, which
     * is precisely why they are the thing that goes wrong unnoticed.
     *
     * kbeautybliss.com served its category archives flat at the site root,
     * because that is what its WooCommerce permalink settings produced. This
     * application serves them at /product-category/{path}/ (URL Contract
     * U-03). App\Support\LegacyCategoryUrls::PATHS is the exact, closed list
     * of the fifteen, and its docblock explains why the flat roots that DO
     * have routes here -- /new-arrivals/, /best-sellers/, /skincare-guide/ --
     * are deliberately not in it.
     *
     * Two of the fifteen are confirmed indexed today, with their live titles:
     * /skincare/ ("Glow Instantly with Korean Skincare Products Online") and
     * /skincare-sets/ ("Best Korean Skin Care Sets for Women in 2024"). They
     * are not hypothetical.
     *
     * ── WHAT "COVERED" MEANS, MATCHED TO THE ACTUAL MATCHER ─────────────────
     *
     * CheckRedirects::row() is
     *
     *     Redirect::query()->where('source', $path)->where('enabled', true)
     *
     * -- an EXACT string match on an ENABLED row, against
     * $request->getPathInfo(), which keeps the trailing slash. So this check
     * asks for exactly what that matcher needs and nothing looser: an enabled
     * row whose source is the trailing-slash spelling, because the trailing-
     * slash spelling is the one Google holds.
     *
     * A row that exists but is switched off, and a row stored only as
     * "/toners" when the indexed address is "/toners/", both still 404 the
     * visitor. They are reported as hits with their own `detail`, because
     * "you have no row" and "your row is off" are one click apart and an
     * operator has to be able to tell them apart.
     *
     * ── COST ────────────────────────────────────────────────────────────────
     *
     * ONE query, thirty bound values, two columns, however many legacy paths
     * there are. Not one query per path -- this file is read on an admin
     * screen that already scans the whole catalogue and it does not need a
     * fifteen-query loop on top. Nothing here loads a model.
     */
    /**
     * Does `org_type` agree with the address settings?
     *
     * ══════════════════════════════════════════════════════════════════════
     * THE TWO STATES A SHOP CAN BE IN WITHOUT BEING TOLD
     * ══════════════════════════════════════════════════════════════════════
     *
     * `BusinessAddress` is correct and this check changes none of it. What it
     * does not do — deliberately, because its job is to emit valid markup and
     * not to have opinions — is tell anybody that the settings disagree. Both
     * of these are reachable from the two screens today and both are silent:
     *
     *   TYPE CLAIMS A PLACE, NO ADDRESS TO PUT IT IN. `Store` or
     *   `LocalBusiness` with `BusinessAddress::postal()` answering null. The
     *   Organization node then says "this is a shop you can walk into" and
     *   carries nothing a search engine could place. `postal()` is asked rather
     *   than the three columns, so "half-filled" counts as no address by the
     *   same rule the emitted node uses — one authority, not two.
     *
     *   COORDINATES THAT CANNOT BE PUBLISHED. `geo()` filled in while the type
     *   is `Organization` or `OnlineStore`. Those are not Places, so
     *   isPlaceType() drops the coordinates and the owner is looking at a
     *   latitude and longitude he typed that no page emits.
     *
     * ── WHAT IS DELIBERATELY NOT A FINDING ──────────────────────────────────
     *
     * An ADDRESS under `OnlineStore` or `Organization`. `address` and
     * `telephone` are properties of Organization, so a company with a
     * registered office and no shopfront is a real, valid and common shape —
     * this shop is online-only and may still want its trading address on the
     * node. Flagging it would be inventing a rule schema.org does not have.
     *
     * An `OnlineStore` with NOTHING filled in, which is this shop today and is
     * not a fault: 24/7 opening hours need no setting at all, because opening
     * hours belong on a Place and an online shop is not one.
     *
     * ── AT MOST ONE HIT ─────────────────────────────────────────────────────
     *
     * A shop is in one state. Two findings against the same pair of screens
     * would read as two problems to fix.
     */
    private static function scanBusinessType(array &$findings): void
    {
        $s = SeoSettings::map();
        $type = SeoSettings::from($s, 'org_type');
        $isPlace = in_array($type, BusinessAddress::PLACE_TYPES, true);

        if ($isPlace && BusinessAddress::postal($s) === null) {
            self::hit($findings, 'business_type_mismatch', [
                'kind' => 'Organization type',
                'name' => $type,
                'url' => '',
                'detail' => 'the type says this business has premises, and no usable street address is '
                    . 'filled in — street, town and a country this shop recognises are all three needed. '
                    . 'Either fill the address in, or set the type to Online store if there is no shopfront.',
            ]);

            return;
        }

        if (! $isPlace && BusinessAddress::geo($s) !== null) {
            self::hit($findings, 'business_type_mismatch', [
                'kind' => 'Organization type',
                'name' => $type,
                'url' => '',
                'detail' => 'map coordinates are filled in and no page publishes them, because '
                    . 'coordinates are a property of a place and this type is not one. Either clear them, '
                    . 'or set the type to Store or Local business if this shop has premises.',
            ]);
        }
    }

    private static function scanLegacyAddresses(array &$findings): void
    {
        // Same guard as every other pass here: a half-migrated install must
        // produce a short report, not a 500.
        if (!Schema::hasTable('redirects')) {
            return;
        }

        $wanted = [];

        foreach (LegacyCategoryUrls::PATHS as $path) {
            $wanted[] = $path;              // /toners/
            $wanted[] = rtrim($path, '/');  // /toners
        }

        /** @var array<string, bool> $state  source => is it enabled */
        $state = [];

        foreach (DB::table('redirects')->select('source', 'enabled')->whereIn('source', $wanted)->get() as $row) {
            $state[(string) $row->source] = (bool) $row->enabled;
        }

        // Three queries for all fifteen, not two or three per path — the
        // budget this method's own comment above sets. See landingPaths().
        $lands = LegacyCategoryUrls::landingPaths();

        foreach (LegacyCategoryUrls::PATHS as $path) {
            $bare = rtrim($path, '/');

            if (($state[$path] ?? false) === true) {
                continue;
            }

            /*
             * ▲ THE SECOND WAY THIS ADDRESS CAN BE COVERED, AND IT NEEDS NO ROW
             *
             * CheckRedirects now derives a 301 for any of the fifteen whose
             * category this shop actually carries — see
             * LegacyCategoryUrls::landingPath(). An address that lands is not a
             * finding, whatever the table says about it, and reporting one
             * anyway would send the owner to fix something that is already
             * fixed. This audit's whole value is that its count means
             * something.
             *
             * ASKED THROUGH landingPaths() AND NOT REIMPLEMENTED HERE. A
             * second copy of "where does this old address go" is a second
             * answer that can drift from the one the middleware serves, and an
             * audit that disagrees with the shop is worse than no audit. That
             * batched method is itself asserted against the per-path one for
             * all fifteen, so this screen and the middleware cannot part
             * company — the arrangement docs/GP-ADDRESSES-LAND.md §13.6 had to
             * retrofit between CanonicalHost and CheckRedirects.
             */
            if (($lands[$path] ?? null) !== null) {
                continue;
            }

            if (array_key_exists($path, $state)) {
                // The row is there and switched off. One click at
                // Store → SEO & Meta → Redirects & 404s.
                $detail = 'redirect is switched off';
            } elseif (array_key_exists($bare, $state)) {
                // The slashless spelling alone never matches the indexed
                // address, because getPathInfo() keeps the slash.
                $detail = 'only "' . $bare . '" is set';
            } else {
                /*
                 * ▲ AND THE REASON IS NOW KNOWN, RATHER THAN ASSUMED.
                 *
                 * With the derived rule in place, "no row" is no longer why
                 * this address fails. It fails because nothing in this shop
                 * answers to that slug, which is a different job for the owner
                 * — import the catalogue, or decide by hand where this address
                 * should go — and it is the only honest thing to put on the
                 * screen. Naming the wrong cause is how an operator spends an
                 * afternoon on the wrong screen; docs/GP-ADDRESSES-LAND.md §4
                 * is the same mistake, found the same way.
                 */
                $detail = 'no category, page or article in this shop answers to "' . $bare . '"';
            }

            self::hit($findings, 'legacy_url_no_redirect', [
                'kind' => 'Old address',
                'name' => $path,
                /*
                 * ▲ WAS toCategoryPath(), WHICH IS A DESTINATION AND NOT
                 * NECESSARILY A PAGE.
                 *
                 * toCategoryPath('/toners/') is the FLAT form,
                 * /product-category/toners/. For a category nested under a
                 * parent that address is itself a 301 (CategoryPath::resolve
                 * via CategoryArchiveController), so the audit was advertising
                 * a two-hop answer; and for a slug this shop does not carry at
                 * all — which, per the branch above, is now the only way to
                 * reach this line — it is a 404. Offering the owner a link to a
                 * not-found page as the fix for a not-found page helps nobody.
                 *
                 * The old address itself is the honest thing to show: it is
                 * what he has to make a decision about.
                 */
                'url' => $path,
                'detail' => $detail,
            ]);
        }
    }

    private static function collectDuplicates(array $tally, array &$findings, string $key): void
    {
        foreach ($tally as $value => $entry) {
            if ($entry['count'] < 2) {
                continue;
            }

            $findings[$key]['count'] += $entry['count'];

            foreach ($entry['rows'] as $row) {
                if (count($findings[$key]['samples']) >= self::SAMPLES) {
                    break 2;
                }

                $findings[$key]['samples'][] = $row + ['detail' => $entry['count'] . '× "' . $value . '"'];
            }
        }
    }

    /**
     * One line an owner can act on.
     *
     * Names the biggest finding rather than printing a score, because a score
     * is a number nobody knows what to do with and "37 products have no
     * description" is a morning's work with an obvious beginning.
     */
    private static function verdict(int $total, array $findings): string
    {
        if ($total === 0) {
            return 'Nothing to scan yet — no published products, categories, brands, articles or pages.';
        }

        $worst = null;
        $worstCount = 0;

        foreach ($findings as $key => $finding) {
            /*
             * ADVISORY FINDINGS DO NOT WIN THE HEADLINE.
             *
             * This line picks the biggest count and calls it "the biggest
             * issue", which was a fair heuristic while every finding described
             * something actually wrong. `product_no_image_alt` is not: no page
             * is broken, every <img> already carries a usable alt from
             * Product::altFor(), and it will be the largest number on this
             * screen on any shop that has not yet written alt text -- which is
             * every shop, the day the check ships. Letting it take the headline
             * would bury "Canonical points somewhere unsafe (1)" behind "no alt
             * text (671)", and a canonical handing this shop's ranking to
             * another domain is worth more than six hundred missing sentences.
             *
             * The finding is still COUNTED, still listed, and still carries its
             * samples. It just does not get to be the sentence at the top.
             * Every finding that existed before this list did ranks exactly as
             * it did before.
             */
            if (in_array($key, self::ADVISORY, true)) {
                continue;
            }

            if ($finding['count'] > $worstCount) {
                $worstCount = $finding['count'];
                $worst = $finding['label'];
            }
        }

        if ($worst === null) {
            return $total . ' indexable URLs scanned. No issues found.';
        }

        return $total . ' indexable URLs scanned. Biggest issue: ' . $worst . ' (' . $worstCount . ').';
    }
}
