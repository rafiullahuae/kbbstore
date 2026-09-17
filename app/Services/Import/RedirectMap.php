<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Redirect;

/**
 * The URL map: old WordPress addresses to this shop's.
 *
 * Phase 13 asks for this beside the row import and it is a genuinely different
 * job, which is why it is not an entity in `ImportRunner`. The entities read a
 * CSV and write rows. This reads the ROWS THAT WERE JUST IMPORTED and works out
 * which addresses Google already has that would now 404.
 *
 * WHY THIS IS NOT HYPOTHETICAL. Fourteen category links in the menu carried
 * WooCommerce-era flat URLs and 404'd until two packages ago. Those were the
 * ones somebody noticed because they were in the menu. Every other flat
 * category URL in Google's index is the same bug with nobody looking at it.
 *
 * THE ONE RULE THE DATA ACTUALLY PROVES is the category one. WooCommerce sites
 * commonly publish a category at its leaf slug — `/product-category/serums/` —
 * while this shop's URL contract U-03 is the full nested path,
 * `/product-category/skincare/treatments/serums/`. `Category::buildPath()` is
 * where that nesting comes from and `CategoryImporter::recomputeTree()` is what
 * fills it in, so after an import the two forms are both derivable and the
 * difference between them is a redirect nobody has written.
 *
 * WHAT THIS DELIBERATELY DOES NOT GUESS:
 *
 *  - BRAND ARCHIVES. U-05 says this shop has no brand archive path at all —
 *    brands are a query parameter on /shop/. What the OLD shop used depends on
 *    which brand plugin it ran (`/brand/`, `/product-brand/`, `/marca/` …) and
 *    inventing one writes 93 redirects from an address that may never have
 *    existed. Asked once, as a question the owner can answer, rather than
 *    guessed 93 times.
 *
 *  - QUERY-STRING PERMALINKS. `/?p=123` and `/?post_type=product&p=123` are
 *    real WordPress addresses and this shop CANNOT redirect them:
 *    `CheckRedirects::findMatch()` matches `source` against
 *    `$request->getPathInfo()`, which excludes the query string entirely. A row
 *    stored for `/?p=123` would never match. Reported as unreachable rather
 *    than written and quietly ineffective.
 *
 *  - PRODUCTS, unless a permalink file says otherwise. WooCommerce's default
 *    product base and this shop's U-01 are both `/product/{slug}/`, and
 *    SlugGuard never rewrites a slug — it adopts or refuses — so an imported
 *    product's address is byte-for-byte the one it had. That is a FINDING and
 *    is reported with its count, because "we checked 671 products and none of
 *    them moved" is the answer, and silence is not.
 *
 * THE PREFIX TRAP, which is the thing most likely to be silently wrong here.
 * `redirects.source` is compared against `getPathInfo()`, which EXCLUDES the
 * `KBB_BASE_PATH` the site is served under, and the one shipped seed
 * (2026_09_14_160000_seed_phase9_post_url_redirects) stores its targets the
 * same prefix-free way. `Category::url()` and `Product::url()` go through
 * `Url::to()`, which ADDS that prefix. So building a redirect out of the
 * models' own url() methods bakes `/kbb-upgrade` into every row and every one
 * of them breaks the day the site moves to the domain root. Every path in here
 * is assembled from raw strings for that reason; `RedirectMapTest` pins it.
 */
final class RedirectMap
{
    /** Decisions, in the three buckets Phase 13 asks every row to land in. */
    public const MIGRATE = 'migrate';

    public const DISCARD = 'discard';

    public const ASK = 'ask';

    /**
     * Work out the whole map without writing any of it.
     *
     * @param  iterable<int, array<string, string>>  $permalinks  rows from an optional
     *          permalink export: type + wc_id + the address the old site published.
     * @return list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>
     */
    public function propose(iterable $permalinks = []): array
    {
        $proposals = [];

        foreach ($this->fromCategoryNesting() as $proposal) {
            $proposals[] = $proposal;
        }

        foreach ($this->fromPermalinks($permalinks) as $proposal) {
            $proposals[] = $proposal;
        }

        return $this->resolve($proposals);
    }

    /**
     * The flat leaf address a WooCommerce category was commonly published at,
     * pointed at the nested one this shop serves.
     *
     * @return list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>
     */
    private function fromCategoryNesting(): array
    {
        $out = [];

        /*
         * WHY THERE IS NO AMBIGUITY CHECK HERE, which is worth stating because
         * the obvious worry is real-sounding and the guard for it would be dead
         * code.
         *
         * The flat address is built from the leaf slug alone, so the question
         * is whether two categories can end in the same slug and both claim
         * `/product-category/serums/`. They cannot: `categories.slug` is
         * `string('slug')->unique()` in the Phase 0 schema, so one slug belongs
         * to exactly one row, and a source built from it is unique by
         * construction. A `$leafCounts[$slug] > 1` branch was written here
         * first, and the database refuses to produce the row that would reach
         * it — a filter that matches nothing, which this repository has already
         * paid for once in `Api\ProductController`. `ImportUrlAndMediaTest`
         * pins the invariant instead: every source in the migrate bucket is
         * distinct.
         *
         * Two DIFFERENT RULES can still collide on one source — a permalink
         * export naming an address this rule also derived — and that is handled
         * in resolve(), where it can actually happen.
         */
        $categories = Category::query()
            ->select(['id', 'slug', 'path', 'depth'])
            ->orderBy('id')
            ->get();

        foreach ($categories as $category) {
            $slug = (string) $category->slug;
            $path = (string) ($category->path ?? '');

            if ($slug === '') {
                continue;
            }

            if ($path === '') {
                /*
                 * No computed path. `CategoryImporter::recomputeTree()` leaves
                 * exactly one kind of row like this: one stranded in a parent
                 * cycle. Its real address is unknown, so it is a question and
                 * not a redirect.
                 */
                $out[] = [
                    'source' => $this->categoryPath($slug),
                    'target' => '',
                    'rule' => 'category-nesting',
                    'decision' => self::ASK,
                    'reason' => 'this category has no computed path, so this shop has no address to send the old '
                        .'one to — it is the parent cycle CategoryImporter reports; fix the parent and re-run',
                    'subject' => 'category '.$category->id.' ('.$slug.')',
                ];

                continue;
            }

            $source = $this->categoryPath($slug);
            $target = $this->categoryPath($path);

            if ($source === $target) {
                /*
                 * A top-level category: its flat address and its nested address
                 * are the same string. Writing this would be a redirect from a
                 * page to itself, which CheckRedirects would serve as an
                 * infinite loop. Discarded, and counted, so the report can say
                 * how many categories needed nothing.
                 */
                $out[] = [
                    'source' => $source,
                    'target' => $target,
                    'rule' => 'category-nesting',
                    'decision' => self::DISCARD,
                    'reason' => 'top-level category — its old address and its new one are the same, so a redirect '
                        .'would point at itself',
                    'subject' => 'category '.$category->id.' ('.$slug.')',
                ];

                continue;
            }

            $out[] = [
                'source' => $source,
                'target' => $target,
                'rule' => 'category-nesting',
                'decision' => self::MIGRATE,
                'reason' => 'WooCommerce published this category at its leaf slug; this shop serves it at its full '
                    .'nested path (U-03)',
                'subject' => 'category '.$category->id.' ('.$path.')',
            ];
        }

        return $out;
    }

    /**
     * Addresses the old site really published, read from an export rather than
     * derived — the only source that can be right about a site whose permalink
     * settings nobody now remembers.
     *
     * @param  iterable<int, array<string, string>>  $rows
     * @return list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>
     */
    private function fromPermalinks(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            $rawUrl = trim((string) ($row['permalink'] ?? $row['url'] ?? $row['old_url'] ?? ''));
            $wcId = (int) trim((string) ($row['wc_id'] ?? $row['id'] ?? ''));

            if ($rawUrl === '' || $wcId <= 0) {
                continue;
            }

            $subject = $type.' '.$wcId;

            /*
             * Only the path survives. A permalink carries scheme, host and
             * possibly a query string; `getPathInfo()` is only ever the path,
             * so anything else in the source would make the row unmatchable.
             */
            $source = (string) (parse_url($rawUrl, PHP_URL_PATH) ?: '');
            $query = (string) (parse_url($rawUrl, PHP_URL_QUERY) ?: '');

            if ($source === '' || $source === '/') {
                $out[] = [
                    'source' => $rawUrl,
                    'target' => '',
                    'rule' => 'permalink',
                    'decision' => self::ASK,
                    'reason' => 'this address carries no path of its own'
                        .($query === '' ? '' : ' — it identifies the page in its query string ("?'.$query.'"), '
                            .'which CheckRedirects cannot match because it compares against getPathInfo()')
                        .', so this shop cannot redirect it',
                    'subject' => $subject,
                ];

                continue;
            }

            $target = $this->currentPathFor($type, $wcId);

            if ($target === null) {
                $out[] = [
                    'source' => $source,
                    'target' => '',
                    'rule' => 'permalink',
                    'decision' => self::ASK,
                    'reason' => 'nothing in this shop carries '.($type === '' ? 'that' : $type).' id '.$wcId
                        .' — either it was never imported, or it is in the discard bucket and this address '
                        .'should 404 on purpose',
                    'subject' => $subject,
                ];

                continue;
            }

            if ($source === $target) {
                $out[] = [
                    'source' => $source,
                    'target' => $target,
                    'rule' => 'permalink',
                    'decision' => self::DISCARD,
                    'reason' => 'the old address and the new one are identical, so there is nothing to redirect',
                    'subject' => $subject,
                ];

                continue;
            }

            $note = $query === ''
                ? 'the old site published this at a different address'
                : 'the old site published this at a different address; its query string ("?'.$query.'") is '
                    .'dropped, because CheckRedirects matches on the path alone';

            $out[] = [
                'source' => $source,
                'target' => $target,
                'rule' => 'permalink',
                'decision' => self::MIGRATE,
                'reason' => $note,
                'subject' => $subject,
            ];
        }

        return $out;
    }

    /**
     * Where this shop serves an imported thing today, as a raw prefix-free
     * path. See the class comment on why this does not call the models' url().
     */
    private function currentPathFor(string $type, int $wcId): ?string
    {
        if (in_array($type, ['product', 'products', 'simple', 'variable'], true)) {
            $product = Product::query()->where('wc_id', $wcId)->first(['slug']);

            return $product === null ? null : '/product/'.$product->slug.'/';
        }

        if (in_array($type, ['category', 'categories', 'product_cat'], true)) {
            $category = Category::query()->where('source_term_id', $wcId)->first(['slug', 'path']);

            if ($category === null) {
                return null;
            }

            $path = (string) ($category->path ?? '');

            return $this->categoryPath($path === '' ? (string) $category->slug : $path);
        }

        if (in_array($type, ['brand', 'brands', 'product_brand', 'pa_brands'], true)) {
            $brand = Brand::query()->where('source_term_id', $wcId)->first(['slug']);

            /*
             * U-05: no brand archive path exists to send them to. The shop page
             * filtered by the brand is the closest real address, and it is a
             * query parameter, which is fine in a TARGET — only the source has
             * to be a bare path.
             */
            return $brand === null ? null : '/shop/?filter_brands='.$brand->slug;
        }

        return null;
    }

    /** `/product-category/{path}/` — U-03, trailing slash and all. */
    private function categoryPath(string $path): string
    {
        return '/product-category/'.trim($path, '/').'/';
    }

    /**
     * Last pass over the whole set, for the problems that only exist between
     * proposals rather than inside one.
     *
     * @param  list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>  $proposals
     * @return list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>
     */
    private function resolve(array $proposals): array
    {
        /*
         * Two proposals claiming one source. The permalink file wins over the
         * derived rule, because it is a record of what the site really served
         * and the derived rule is an inference about what it probably served.
         * Both are kept in the output — the loser as a question — because an
         * importer that silently drops one of two conflicting instructions is
         * the thing this whole file exists to avoid.
         */
        $bySource = [];

        foreach ($proposals as $index => $proposal) {
            if ($proposal['decision'] !== self::MIGRATE) {
                continue;
            }

            $bySource[$proposal['source']][] = $index;
        }

        foreach ($bySource as $source => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            /*
             * TWO RULES THAT AGREE ARE NOT A CONFLICT, and this is the case
             * that actually happens rather than the one the code was written
             * for.
             *
             * Both rules here describe the SAME category from two directions.
             * fromCategoryNesting() derives "/product-category/serums/ ->
             * /product-category/skincare/serums/" from the tree that was just
             * imported; fromPermalinks() reads the same move out of the old
             * site's own permalink export. On a nested category that the
             * permalink file also covers -- which, on a real export, is every
             * nested category -- both fire, with byte-identical source AND
             * byte-identical target, and the loser was demoted to ASK with the
             * reason "another rule claims the same old address and points it
             * somewhere else, at X" where X is the address it also points to.
             *
             * Measured on a 671-product, 29-category fixture: 27 of the 52
             * non-discard rows were questions containing no disagreement. The
             * owner has to read every one to discover that, and the ones that
             * are real disagreements are mixed in among them. A question list
             * that is mostly noise is a question list nobody finishes, which is
             * the same failure as not asking.
             *
             * So agreement collapses: one proposal stays, the duplicates become
             * DISCARD with the reason stated, and ASK keeps only the rows where
             * two rules genuinely send one old address to two different places.
             */
            $targets = array_unique(array_map(
                static fn (int $i): string => $proposals[$i]['target'],
                $indexes,
            ));

            if (count($targets) === 1) {
                $keep = $indexes[0];

                foreach ($indexes as $index) {
                    // The permalink export is the record of what was really
                    // served, so it is the one kept where it is present.
                    if ($proposals[$index]['rule'] === 'permalink') {
                        $keep = $index;

                        break;
                    }
                }

                foreach ($indexes as $index) {
                    if ($index === $keep) {
                        continue;
                    }

                    $proposals[$index]['decision'] = self::DISCARD;
                    $proposals[$index]['reason'] = 'a second rule proposes exactly this redirect, to exactly '
                        .'this destination — one of them is enough, and two identical rows are not a question '
                        .'for anybody';
                }

                continue;
            }

            $winner = null;

            foreach ($indexes as $index) {
                if ($proposals[$index]['rule'] === 'permalink') {
                    $winner = $index;

                    break;
                }
            }

            foreach ($indexes as $index) {
                if ($index === $winner) {
                    continue;
                }

                $proposals[$index]['decision'] = self::ASK;
                $proposals[$index]['reason'] = 'another rule claims the same old address "'.$source.'" and points '
                    .'it somewhere else'
                    .($winner === null
                        ? ' — neither is from a permalink export, so neither can be preferred automatically'
                        : ', at "'.$proposals[$winner]['target'].'", which came from the permalink export and '
                            .'is what the old site really served');
            }
        }

        /*
         * A redirect whose target is itself somebody else's source. A browser
         * follows A -> B -> C, but two hops is worse for SEO than one and a
         * slug that moves twice grows the chain a link at a time —
         * RedirectManager collapses these for slug edits and the same rule
         * applies here. Collapsed to the final destination, and said out loud.
         */
        $targets = [];

        foreach ($proposals as $index => $proposal) {
            if ($proposal['decision'] === self::MIGRATE) {
                $targets[$proposal['source']] = $index;
            }
        }

        foreach ($proposals as $index => $proposal) {
            if ($proposal['decision'] !== self::MIGRATE) {
                continue;
            }

            $seen = [$proposal['source'] => true];
            $target = $proposal['target'];
            $hops = 0;

            while (isset($targets[$target]) && ! isset($seen[$target]) && $hops < 10) {
                $seen[$target] = true;
                $target = $proposals[$targets[$target]]['target'];
                $hops++;
            }

            if ($hops === 0) {
                continue;
            }

            if ($target === $proposal['source']) {
                $proposals[$index]['decision'] = self::ASK;
                $proposals[$index]['reason'] = 'following this redirect leads back to where it started — a loop, '
                    .'which would make the address unreachable rather than moved';

                continue;
            }

            $proposals[$index]['target'] = $target;
            $proposals[$index]['reason'] .= '; collapsed through '.$hops.' intermediate '
                .($hops === 1 ? 'redirect' : 'redirects').' so the visitor makes one hop, not '.($hops + 1);
        }

        return $proposals;
    }

    /**
     * Compare the map against `redirects` as it stands, so the report can say
     * what a write would actually change rather than what it would send.
     *
     * @param  list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>  $proposals
     * @return array{create: list<array<string, string>>, update: list<array<string, string>>, unchanged: list<array<string, string>>, conflict: list<array<string, string>>}
     */
    public function diff(array $proposals): array
    {
        $out = ['create' => [], 'update' => [], 'unchanged' => [], 'conflict' => []];

        $existing = Redirect::query()
            ->whereIn('source', array_values(array_unique(array_column($proposals, 'source'))))
            ->get(['source', 'target', 'auto_created'])
            ->keyBy('source');

        foreach ($proposals as $proposal) {
            if ($proposal['decision'] !== self::MIGRATE) {
                continue;
            }

            $current = $existing->get($proposal['source']);

            if ($current === null) {
                $out['create'][] = $proposal;

                continue;
            }

            if ((string) $current->target === $proposal['target']) {
                $out['unchanged'][] = $proposal;

                continue;
            }

            /*
             * Somebody already pointed this address somewhere else. If the
             * existing row was written by hand it is a decision a person made
             * and this map does not get to overrule it; if it was written by
             * automatic bookkeeping it can be corrected.
             */
            if ((bool) $current->auto_created === false) {
                $out['conflict'][] = $proposal + ['current' => (string) $current->target];

                continue;
            }

            $out['update'][] = $proposal + ['current' => (string) $current->target];
        }

        return $out;
    }
}
