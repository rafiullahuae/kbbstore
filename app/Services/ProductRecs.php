<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Store\ProductController;
use App\Models\Product;
use App\Support\AlsoLikePicks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The three recommendation blocks at the foot of a product page.  (Lane RP)
 *
 * The owner, 8 October: three blocks — a SLIDER, a GRID, a SLIDER — and the
 * first one context-aware: "if the shopper reached the product from a category
 * page, more from that category; from a brand page, more from that brand."
 *
 *   1  slider  "You may also like" with two tabs, More from {brand} and
 *              More {category}. BOTH lists are in the HTML (real links, so
 *              Google crawls both and the URL never changes); which tab is
 *              open first is decided in the browser from a sessionStorage
 *              hint the listing page wrote on the click (resources/js/kbb/
 *              shop.js), and is the brand when there is no hint. The server
 *              response never varies on where the shopper came from.
 *   2  grid    "Complete your routine": the next steps of a routine — the
 *              complementary shelves App\Services\BuyTogetherPairs already
 *              names (cleanser → toner → serum → moisturiser → sunscreen, his
 *              overrides included), one from each in turn, IN STOCK FIRST and
 *              products that share a tag (skin concern) with this one first,
 *              then best sellers. Nothing shown in block 1 or in "Buy these
 *              together" is repeated.
 *   3  slider  "Continue shopping": the shopper's recently viewed products —
 *              the `kbb_viewed` cookie ProductController::rememberViewed()
 *              already writes and the cart panel's Browsed tab already reads —
 *              then the shop's best sellers. A crawler and a first visit carry
 *              no cookie and see best sellers: real, crawlable links.
 *
 * ── ▲ TWO QUERIES FOR ALL THREE, COLD OR WARM ──────────────────────────────
 *
 * This replaces App\Services\AlsoLikeRail's two statements on the default
 * page, it does not add to them:
 *
 *   COLD  ONE `UNION ALL` — the brand, the category, the category tree (top
 *         up), one SELECT per complementary shelf, the best sellers and the
 *         shopper's viewed ids — then the brands, eager-loaded once for every
 *         card of every block.
 *   WARM  The chosen pools are cached per product (ten minutes; a saved
 *         product forgets its own, a changed setting changes the fingerprint),
 *         so a repeat view reads ONE `WHERE id IN (…)` — cached ids and the
 *         viewed ids together — then the brands.
 *
 * Visibility and stock are re-applied on the warm read, as AlsoLikeRail does.
 * The viewed ids are never cached: they are the shopper's, not the product's.
 *
 * When the owner keeps block 1 as ONE mixed row (Layout = "One row"), or a
 * product's own picks say "Only my picks", or the rule is not the brand +
 * category mix, block 1 is AlsoLikeRail exactly as before — byte for byte —
 * and blocks 2 and 3 cost their own union and brand load (two more queries,
 * only on a shop that chose that).
 */
class ProductRecs
{
    public const CACHE_PREFIX = 'kbb.recs.';

    public const TTL = 600;

    /** Complementary shelves read for block 2 — BuyTogetherPairs::MAX_PAIRS. */
    public const MAX_SHELVES = 5;

    /** The longest list block 3 reads from the cookie (rememberViewed keeps 12). */
    public const MAX_VIEWED = 12;

    /** The sessionStorage key the listing script writes. Pinned by a test. */
    public const HINT_KEY = 'kbb_rp_from';

    private const BRAND = 'b';

    private const CATEGORY = 'c';

    private const TREE = 'p';

    private const MANUAL = 'm';

    private const TAG = 't';

    private const BEST = 'f';

    private const VIEWED = 'v';

    /** Block 2's ranked order, as cached. */
    private const ROUTINE = 'R';

    /** Room in a cached list for what a visit takes out ("Buy these together" draws ≤ 5). */
    private const SLACK = 6;

    public function __construct(
        private AlsoLikeSettings $settings,
        private ProductSections $sections,
        private BuyTogetherPairs $pairs,
        private SettingsService $settingsSnapshot,
    ) {}

    /** Drop one product's cached pools. Called from Product::booted(). */
    public static function forget(int $productId): void
    {
        if ($productId > 0) {
            Cache::forget(self::CACHE_PREFIX.$productId);
        }
    }

    /**
     * Everything the three partials need.
     *
     * @param  list<int>  $onPage  ids already drawn elsewhere on the page ("Buy these together")
     * @return array{alsoLike: array<string, mixed>, routine: array<string, mixed>, recent: array<string, mixed>, order: list<string>}
     */
    public function forProduct(Product $product, Request $request, array $onPage = []): array
    {
        $c = $this->settings->all();
        $none = collect();
        $out = [
            'alsoLike' => ['products' => $none, 'config' => $c, 'wording' => AlsoLikeSettings::wording($c), 'panels' => []],
            'routine' => ['products' => $none, 'title' => '', 'eyebrow' => ''],
            'recent' => ['products' => $none, 'title' => '', 'eyebrow' => '', 'viewed' => false, 'seen' => []],
            'order' => self::order($c),
        ];

        // Sections → "You may also like" off for both devices: the whole foot
        // of the page is off, and NOTHING is asked of the database.
        if ($this->sections->hidden('related')) {
            return $out;
        }

        $picks = AlsoLikePicks::read($product);
        $tabs = $c['enabled'] && self::tabsMode($c, $picks);
        $wantRoutine = (bool) $c['routine_on'];
        $wantRecent = (bool) $c['recent_on'];

        // Block 1 as it was: AlsoLikeRail, untouched.
        if ($c['enabled'] && ! $tabs) {
            $out['alsoLike'] = app(AlsoLikeRail::class)->forProduct($product) + ['panels' => []];
        }

        if (! $tabs && ! $wantRoutine && ! $wantRecent) {
            return $out;
        }

        $viewed = $wantRecent ? self::viewedIds($request, (int) $product->id) : [];
        $pools = $this->pools($product, $c, $picks, $tabs, $wantRoutine, $wantRecent, $viewed);
        $out = $this->assemble($product, $c, $picks, $tabs, $wantRoutine, $wantRecent, $pools, $out, $onPage);

        // A product with neither a brand nor a category has no tab to draw:
        // it gets the one row it always had rather than nothing.
        if ($tabs && $out['alsoLike']['panels'] === []) {
            $out['alsoLike'] = app(AlsoLikeRail::class)->forProduct($product) + ['panels' => []];
        }

        return $out;
    }

    /**
     * The carousel's cards-in-view, for block 3: block 1's own two settings,
     * each held to its option list (the same checks the block 1 partial makes),
     * so the two sliders on one page are the same size.
     *
     * @param  array<string, mixed>  $c
     * @return array{d: int, m: string, arrM: string}
     */
    public static function track(array $c): array
    {
        $m = (string) ($c['per_phone'] ?? '2.3');
        $m = in_array($m, array_map('strval', array_keys(AlsoLikeSettings::SCHEMA['per_phone'][4])), true) ? $m : '2.3';

        return [
            'd' => max(1, min(6, (int) ($c['per_desktop'] ?? 5))),
            'm' => $m,
            'arrM' => ! empty($c['arrows_m']) ? ' ymal-arr-m' : '',
        ];
    }

    /**
     * Block 1 is two tabs when the owner left Layout on "Two tabs", the rule is
     * the brand + category mix (the two lists the tabs ARE), and the product's
     * own picks do not say "Only my picks". Anything else he chose is a single
     * row, exactly as AlsoLikeRail draws it.
     *
     * @param  array<string, mixed>  $c
     * @param  array{mode: string, ids: list<int>}  $picks
     */
    public static function tabsMode(array $c, array $picks): bool
    {
        return ($c['layout'] ?? 'tabs') === 'tabs'
            && ($c['rule'] ?? 'mix') === 'mix'
            && ! ($picks['mode'] === AlsoLikePicks::MODE_ONLY && $picks['ids'] !== []);
    }

    /**
     * The block order, from a select that only stores its own options.
     *
     * @param  array<string, mixed>  $c
     * @return list<string>
     */
    public static function order(array $c): array
    {
        $o = (string) ($c['order'] ?? '123');

        return array_key_exists($o, AlsoLikeSettings::ORDERS) ? str_split($o) : ['1', '2', '3'];
    }

    /**
     * The shopper's recently viewed ids, newest first, without this product.
     * From the cookie only — integers, de-duplicated, at most MAX_VIEWED.
     *
     * @return list<int>
     */
    public static function viewedIds(Request $request, int $currentId): array
    {
        $raw = (string) $request->cookie('kbb_viewed', '');
        $out = [];

        foreach (explode(',', substr($raw, 0, 200)) as $id) {
            $id = (int) $id;

            if ($id > 0 && $id !== $currentId && ! in_array($id, $out, true)) {
                $out[] = $id;
            }

            if (count($out) >= self::MAX_VIEWED) {
                break;
            }
        }

        return $out;
    }

    /**
     * Every candidate, by source: cached ids + one IN when warm, one union
     * when cold. Either way the brands are loaded once, for all of them.
     *
     * @param  array<string, mixed>  $c
     * @param  array{mode: string, ids: list<int>}  $picks
     * @param  list<int>  $viewed
     * @return array<string, list<Product>>
     */
    private function pools(Product $product, array $c, array $picks, bool $tabs, bool $wantRoutine, bool $wantRecent, array $viewed): array
    {
        $categoryIds = $this->categoryIds($product);
        $specific = $this->specificCategory($product);
        // The routine shelves are resolved on the COLD path only: they need
        // BuyTogetherPairs' category list, and a warm view must not. What
        // decides them — his pair overrides, from the settings snapshot already
        // in memory — is in the fingerprint instead; a renamed shelf is picked
        // up within the TTL.
        $fp = md5(json_encode([
            // The cached shape; a new one is never read as an old one.
            2,
            $c['rule'], $c['fill'], $c['count'], $c['hide_oos'], $c['layout'], $c['routine_on'], $c['routine_count'],
            $c['recent_on'], $c['recent_count'], $tabs, $picks, $product->brand_id, $categoryIds, $specific,
            $wantRoutine ? $this->settingsSnapshot->get(BuyTogetherPairs::SETTING, null) : null,
        ]) ?: '');

        $key = self::CACHE_PREFIX.(int) $product->id;
        $hit = Cache::get($key);

        if (is_array($hit) && ($hit['fp'] ?? null) === $fp && is_array($hit['ids'] ?? null)) {
            return $this->hydrate($hit['ids'], $viewed, (bool) $c['hide_oos']);
        }

        $shelves = $wantRoutine ? $this->shelves($product, $categoryIds) : [];
        $count = (int) $c['count'];
        $parts = [];

        if ($tabs) {
            if ($picks['mode'] === AlsoLikePicks::MODE_FIRST && $picks['ids'] !== []) {
                $parts[] = $this->part($product, $c, self::MANUAL, count($picks['ids']))->whereIn('products.id', $picks['ids']);
            }

            if ($product->brand_id !== null) {
                $parts[] = $this->bestFirst($this->part($product, $c, self::BRAND, $count))
                    ->where('products.brand_id', (int) $product->brand_id);
            }

            if ($specific !== null) {
                $parts[] = $this->bestFirst($this->part($product, $c, self::CATEGORY, $count))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('category_product as rpc')
                        ->whereColumn('rpc.product_id', 'products.id')
                        ->where('rpc.category_id', $specific));

                if ($c['fill']) {
                    // The shelf's parent and its sibling shelves, as AlsoLikeRail's tree.
                    $parents = fn ($q) => $q->select('rpp.parent_id')->from('categories as rpp')
                        ->where('rpp.id', $specific)->whereNotNull('rpp.parent_id');

                    $parts[] = $this->bestFirst($this->part($product, $c, self::TREE, $count))
                        ->whereExists(fn ($q) => $q->selectRaw('1')->from('category_product as rpt')
                            ->whereColumn('rpt.product_id', 'products.id')
                            ->where(fn ($w) => $w->whereIn('rpt.category_id', $parents)
                                ->orWhereIn('rpt.category_id', fn ($s) => $s->select('rpk.id')->from('categories as rpk')
                                    ->whereIn('rpk.parent_id', $parents))));
                }
            }
        }

        $routineCount = (int) $c['routine_count'];

        // One turn each, so a shelf needs at most the block's count; a lone
        // shelf carries the slack the exclusions may eat.
        $perShelf = count($shelves) > 1 ? $routineCount : $routineCount + self::SLACK;

        foreach ($shelves as $i => $shelf) {
            $parts[] = $this->routineOrder($this->part($product, $c, 'r'.$i, $perShelf), $product)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('category_product as rpr')
                    ->whereColumn('rpr.product_id', 'products.id')
                    ->where('rpr.category_id', $shelf));
        }

        if ($wantRoutine && $shelves === []) {
            // No routine shelf for this product (a device, a shelf with no
            // kind): products that share a tag with it, from OTHER shelves.
            $parts[] = $this->routineOrder($this->part($product, $c, self::TAG, $routineCount + self::SLACK), $product)
                ->whereExists(self::sharesTag((int) $product->id))
                ->when($categoryIds !== [], fn ($q) => $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('category_product as rpo')
                    ->whereColumn('rpo.product_id', 'products.id')
                    ->whereIn('rpo.category_id', $categoryIds)));
        }

        if ($wantRoutine || $wantRecent) {
            // The top-up for every block, enough to survive every exclusion.
            // Block 1 can hold the shop's top sellers twice over (two tabs):
            // the pool must outlast that AND still fill blocks 2 and 3.
            $need = ($tabs ? 2 * $count : 0) + ($wantRoutine ? $routineCount : 0) + ($wantRecent ? (int) $c['recent_count'] : 0) + self::SLACK;
            $parts[] = $this->inStockFirst($this->part($product, $c, self::BEST, min(60, $need)))
                ->orderByDesc('products.total_sales')->orderByDesc('products.id');
        }

        $cacheable = $parts;

        if ($viewed !== []) {
            $parts[] = $this->part($product, $c, self::VIEWED, count($viewed))->whereIn('products.id', $viewed);
        }

        if ($parts === []) {
            return [];
        }

        $query = array_shift($parts);

        foreach ($parts as $part) {
            $query->unionAll($part->toBase());
        }

        $rows = $query->get();
        $rows->load('brand:id,name,slug');
        \App\Support\SetPricing::prime($rows);

        $pools = [];

        foreach ($rows as $row) {
            $pools[(string) $row->getAttribute('rp_src')][] = $row;
        }

        $ranked = $this->rank($product, $c, $picks, $tabs, $pools);

        if ($cacheable !== []) {
            Cache::put($key, ['fp' => $fp, 'ids' => array_map(fn ($list) => array_map(fn ($m) => (int) $m->id, $list), $ranked)], self::TTL);
        }

        $ranked[self::VIEWED] = self::inOrder(self::byId($pools[self::VIEWED] ?? []), $viewed);

        return $ranked;
    }

    /**
     * The cold path's pools, cut down to what the blocks can use, in order —
     * this is what is cached, so a warm view loads ~60 rows rather than every
     * candidate the union read:
     *
     *   b  block 1's brand tab, final       c  block 1's category tab, final
     *   r  block 2's routine order (one shelf at a time, then shared tags),
     *      without block 1, with SLACK for what "Buy these together" takes
     *   f  best sellers without block 1: block 2's top-up and block 3's
     *
     * Nothing per-request is decided here: the exclusions that vary by visit
     * (Buy these together, the viewed cookie) run in assemble().
     *
     * @param  array<string, mixed>  $c
     * @param  array{mode: string, ids: list<int>}  $picks
     * @param  array<string, list<Product>>  $pools
     * @return array<string, list<Product>>
     */
    private function rank(Product $product, array $c, array $picks, bool $tabs, array $pools): array
    {
        $self = (int) $product->id;
        $skip = [$self => true];
        $out = [];
        $slack = self::SLACK + ($tabs ? 0 : (int) $c['count']);

        if ($tabs) {
            $count = (int) $c['count'];
            $lead = $picks['mode'] === AlsoLikePicks::MODE_FIRST && $picks['ids'] !== []
                ? self::inOrder(self::byId($pools[self::MANUAL] ?? []), $picks['ids'])
                : [];

            $out[self::BRAND] = self::take(array_merge($lead, $pools[self::BRAND] ?? []), [$self => true], $count);
            $out[self::CATEGORY] = self::take(array_merge($lead, $pools[self::CATEGORY] ?? [], $c['fill'] ? ($pools[self::TREE] ?? []) : []), [$self => true], $count);
            $skip += self::idsOf($out[self::BRAND]) + self::idsOf($out[self::CATEGORY]);
        }

        $shelves = [];

        foreach ($pools as $src => $list) {
            if (preg_match('/^r(\d+)$/', (string) $src, $m) === 1) {
                $shelves[(int) $m[1]] = $list;
            }
        }

        ksort($shelves);
        $want = (int) $c['routine_count'] + $slack;
        $routine = self::roundRobin(array_values($shelves), $skip, $want);
        $out[self::ROUTINE] = array_merge($routine, self::take($pools[self::TAG] ?? [], $skip + self::idsOf($routine), $want - count($routine)));
        // Best sellers not already in block 1: block 2's top-up when a shelf
        // runs dry, and block 3's fallback after whatever block 2 drew.
        $out[self::BEST] = self::take($pools[self::BEST] ?? [], $skip, (int) $c['routine_count'] + (int) $c['recent_count'] + $slack);

        return $out;
    }

    /**
     * The warm path: every cached id and every viewed id in ONE statement,
     * visibility and stock re-applied, then the brands.
     *
     * @param  array<string, list<int>>  $cached
     * @param  list<int>  $viewed
     * @return array<string, list<Product>>
     */
    private function hydrate(array $cached, array $viewed, bool $hideOos): array
    {
        $all = $viewed;

        foreach ($cached as $list) {
            foreach ((array) $list as $id) {
                $all[] = (int) $id;
            }
        }

        $all = array_values(array_unique(array_filter($all)));

        if ($all === []) {
            return [];
        }

        $q = Product::query()
            ->select(ProductController::CARD_COLUMNS)
            ->visible()
            ->whereIn('id', $all)
            ->with('brand:id,name,slug');

        if ($hideOos) {
            $q->where(fn ($w) => $w->whereNull('stock_status')->orWhere('stock_status', '!=', 'outofstock'));
        }

        $rows = $q->get();
        \App\Support\SetPricing::prime($rows);
        $byId = $rows->keyBy(fn ($p) => (int) $p->id)->all();

        $pools = [];

        foreach ($cached as $src => $list) {
            $pools[(string) $src] = self::inOrder($byId, array_map('intval', (array) $list));
        }

        $pools[self::VIEWED] = self::inOrder($byId, $viewed);

        return $pools;
    }

    /**
     * The three blocks, from the pools, in PHP. Exclusions run here, per
     * request, so "Buy these together" — which may pick at random on every
     * visit — is never repeated below it.
     *
     * @param  array<string, mixed>  $c
     * @param  array{mode: string, ids: list<int>}  $picks
     * @param  array<string, list<Product>>  $pools
     * @param  array<string, mixed>  $out
     * @param  list<int>  $onPage
     * @return array<string, mixed>
     */
    private function assemble(Product $product, array $c, array $picks, bool $tabs, bool $wantRoutine, bool $wantRecent, array $pools, array $out, array $onPage): array
    {
        $self = (int) $product->id;
        $taken = [$self => true];

        foreach ($onPage as $id) {
            $taken[(int) $id] = true;
        }

        $block1 = [];

        if ($tabs) {
            $count = (int) $c['count'];
            $brand = self::take($pools[self::BRAND] ?? [], [$self => true], $count);
            $cat = self::take($pools[self::CATEGORY] ?? [], [$self => true], $count);

            $panels = [];
            $brandModel = $product->relationLoaded('brand') ? $product->brand : null;

            if ($brand !== [] && $brandModel !== null) {
                $panels['brand'] = [
                    'key' => 'brand',
                    'label' => (string) __('store.product.recs_tab_brand', ['brand' => (string) $brandModel->t('name')]),
                    'paths' => [self::pathOf($brandModel->url())],
                    'products' => collect($brand),
                ];
            }

            $specific = $this->specificCategory($product);
            $specificModel = $specific === null ? null : $product->categories->firstWhere('id', $specific);

            if ($cat !== [] && $specificModel !== null) {
                $panels['category'] = [
                    'key' => 'category',
                    'label' => (string) __('store.product.recs_tab_category', ['category' => (string) $specificModel->t('name')]),
                    'paths' => self::categoryPaths($product),
                    'products' => collect($cat),
                ];
            }

            // His default first; the browser may open the other from the hint.
            if (($c['first'] ?? 'brand') === 'category') {
                $panels = array_reverse($panels, true);
            }

            $panels = array_values($panels);

            foreach ($panels as $p) {
                foreach ($p['products'] as $m) {
                    $block1[(int) $m->id] = true;
                }
            }

            if ($panels !== []) {
                $wording = AlsoLikeSettings::wording($c);
                $ar = ! \App\Support\Locale::isDefault() && \App\Support\Locale::current() === 'ar';

                // The eyebrow's shipped line was "Complete your routine" —
                // block 2's heading now. An empty box reads "More like this".
                if (trim((string) ($c[$ar ? 'eyebrow_ar' : 'eyebrow'] ?? '')) === '') {
                    $wording['eyebrow'] = (string) __('store.product.recs_more_eyebrow');
                }

                $out['alsoLike'] = [
                    // Every card of both tabs, brand first: what
                    // ProductDesktopSections::drawn() and `$related` read.
                    'products' => collect($panels)->flatMap(fn ($p) => $p['products'])->unique('id')->values(),
                    'config' => $c,
                    'wording' => $wording,
                    'panels' => $panels,
                ];
            }
        } else {
            foreach ($out['alsoLike']['products'] as $m) {
                $block1[(int) $m->id] = true;
            }
        }

        $taken += $block1;

        if ($wantRoutine) {
            $routine = self::take($pools[self::ROUTINE] ?? [], $taken, (int) $c['routine_count']);

            if ($c['fill']) {
                $routine = array_merge($routine, self::take($pools[self::BEST] ?? [], $taken + self::idsOf($routine), (int) $c['routine_count'] - count($routine)));
            }

            if ($routine !== []) {
                $out['routine'] = ['products' => collect($routine)] + self::wording($c, 'routine', false);
                $taken += self::idsOf($routine);
            }
        }

        if ($wantRecent) {
            $n = (int) $c['recent_count'];
            $seen = self::take($pools[self::VIEWED] ?? [], $taken, $n);
            $recent = array_merge($seen, self::take($pools[self::BEST] ?? [], $taken + self::idsOf($seen), $n - count($seen)));

            if ($recent !== []) {
                $out['recent'] = ['products' => collect($recent), 'viewed' => $seen !== [], 'seen' => array_keys(self::idsOf($seen))]
                    + self::wording($c, 'recent', $seen !== []);
            }
        }

        return $out;
    }

    /**
     * Heading and eyebrow for block 2 or 3 in this page's language. A typed
     * heading wins; an empty box is the interface string, so Translation
     * keeps answering for a shop that never typed one.
     *
     * @param  array<string, mixed>  $c
     * @return array{title: string, eyebrow: string}
     */
    public static function wording(array $c, string $block, bool $viewed): array
    {
        $ar = ! \App\Support\Locale::isDefault() && \App\Support\Locale::current() === 'ar';
        $typed = trim((string) ($c[$block.'_title'.($ar ? '_ar' : '')] ?? ''));

        return [
            'title' => $typed !== '' ? $typed : (string) __('store.product.recs_'.$block.'_heading'),
            'eyebrow' => (string) __($block === 'routine'
                ? 'store.product.recs_routine_eyebrow'
                : ($viewed ? 'store.product.recs_recent_eyebrow' : 'store.product.recs_best_eyebrow')),
        ];
    }

    /**
     * Complementary shelves for block 2: BuyTogetherPairs' list for this
     * product's anchor shelf, minus the product's own shelves. No query when
     * the category cache is warm — and "Buy these together" has already
     * warmed it on any page that draws that section.
     *
     * @param  list<int>  $own
     * @return list<int>
     */
    private function shelves(Product $product, array $own): array
    {
        $anchor = $this->pairs->anchorFor($product);

        if ($anchor === null) {
            return [];
        }

        $out = [];

        foreach ($this->pairs->pairsFor($anchor) as $id) {
            if (! in_array((int) $id, $own, true) && ! in_array((int) $id, $out, true)) {
                $out[] = (int) $id;
            }
        }

        return array_slice($out, 0, self::MAX_SHELVES);
    }

    /**
     * The one shelf block 1's category tab is about: the deepest of the
     * product's categories (Skincare › Serums → Serums), then its primary
     * category, then the oldest. From the relation the page already loaded.
     */
    private function specificCategory(Product $product): ?int
    {
        if (! $product->relationLoaded('categories') || $product->categories->isEmpty()) {
            return null;
        }

        $primary = $product->category_id === null ? null : (int) $product->category_id;

        return (int) $product->categories
            ->sortBy(fn ($cat) => [-substr_count(trim((string) $cat->path, '/'), '/'), (int) $cat->id === $primary ? 0 : 1, (int) $cat->id])
            ->first()->id;
    }

    /** @return list<int> */
    private function categoryIds(Product $product): array
    {
        $ids = $product->relationLoaded('categories')
            ? $product->categories->pluck('id')
            : $product->categories()->pluck('categories.id');

        return $ids->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    /** One SELECT of the union: the card's columns, tagged with its source. */
    private function part(Product $product, array $c, string $src, int $limit): Builder
    {
        $columns = array_map(fn ($col) => 'products.'.$col, ProductController::CARD_COLUMNS);

        $q = Product::query()
            ->select($columns)
            ->selectRaw("'{$src}' as rp_src")
            ->visible()
            ->where('products.id', '!=', (int) $product->id)
            ->limit(max(1, $limit));

        if ($c['hide_oos']) {
            $q->where(fn ($w) => $w->whereNull('products.stock_status')->orWhere('products.stock_status', '!=', 'outofstock'));
        }

        return $q;
    }

    private function bestFirst(Builder $q): Builder
    {
        return $q->orderByDesc('products.total_sales')->orderByDesc('products.id');
    }

    /** In stock first — what "Hide out-of-stock products: off" still owes a shopper. */
    private function inStockFirst(Builder $q): Builder
    {
        return $q->orderByRaw("CASE WHEN products.stock_status = 'outofstock' THEN 1 ELSE 0 END");
    }

    /**
     * Block 2's order inside one shelf: in stock, then sharing a tag (a skin
     * concern) with the product on the page, then best sellers.
     */
    private function routineOrder(Builder $q, Product $product): Builder
    {
        $id = (int) $product->id;

        return $this->inStockFirst($q)
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM product_tag rpa WHERE rpa.product_id = products.id AND rpa.tag_id IN '
                ."(SELECT rpb.tag_id FROM product_tag rpb WHERE rpb.product_id = {$id})) THEN 0 ELSE 1 END")
            ->orderByDesc('products.total_sales')->orderByDesc('products.id');
    }

    private static function sharesTag(int $id): \Closure
    {
        return fn ($q) => $q->selectRaw('1')->from('product_tag as rps')
            ->whereColumn('rps.product_id', 'products.id')
            ->whereIn('rps.tag_id', fn ($s) => $s->select('rpu.tag_id')->from('product_tag as rpu')->where('rpu.product_id', $id));
    }

    /**
     * @param  array<int, Product>  $byId  keyed by id
     * @param  list<int>  $ids
     * @return list<Product>
     */
    private static function inOrder(array $byId, array $ids): array
    {
        $out = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * @param  list<Product>  $list
     * @return array<int, Product>
     */
    private static function byId(array $list): array
    {
        $out = [];

        foreach ($list as $p) {
            $out[(int) $p->id] ??= $p;
        }

        return $out;
    }

    /**
     * Up to $n of $list, in order, skipping anything in $skip and repeats.
     *
     * @param  list<Product>  $list
     * @param  array<int, true>  $skip
     * @return list<Product>
     */
    private static function take(array $list, array $skip, int $n): array
    {
        $out = [];

        foreach ($list as $p) {
            if (count($out) >= $n) {
                break;
            }

            $id = (int) $p->id;

            if (! isset($skip[$id])) {
                $out[] = $p;
                $skip[$id] = true;
            }
        }

        return $out;
    }

    /**
     * One from each shelf in turn — a routine reads cleanser, toner, serum,
     * moisturiser, sunscreen, then the second of each — skipping $skip.
     *
     * @param  list<list<Product>>  $shelves
     * @param  array<int, true>  $skip
     * @return list<Product>
     */
    private static function roundRobin(array $shelves, array $skip, int $n): array
    {
        $out = [];
        $at = array_fill(0, count($shelves), 0);

        while (count($out) < $n) {
            $moved = false;

            foreach ($shelves as $i => $list) {
                while ($at[$i] < count($list)) {
                    $p = $list[$at[$i]++];

                    if (! isset($skip[(int) $p->id])) {
                        $out[] = $p;
                        $skip[(int) $p->id] = true;
                        $moved = true;

                        break;
                    }
                }

                if (count($out) >= $n) {
                    break;
                }
            }

            if (! $moved) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<Product>  $list
     * @return array<int, true>
     */
    private static function idsOf(array $list): array
    {
        $out = [];

        foreach ($list as $p) {
            $out[(int) $p->id] = true;
        }

        return $out;
    }

    /**
     * Every listing this product is reached from by shelf: each of its
     * categories AND their parents (Skincare › Toners lists the toner on
     * /collections/skincare/ too). From `path`, which the page already loaded
     * — no query. Decoded paths, as the listing script stores them.
     *
     * @return list<string>
     */
    private static function categoryPaths(Product $product): array
    {
        $out = [];

        foreach ($product->categories as $cm) {
            $segments = array_values(array_filter(explode('/', trim((string) ($cm->path ?: $cm->slug), '/')), 'strlen'));

            for ($n = count($segments); $n >= 1; $n--) {
                $out[] = self::pathOf(\App\Support\Url::to(
                    \App\Support\UrlScheme::collection(implode('/', array_slice($segments, 0, $n)))
                ));
            }
        }

        return array_values(array_unique($out));
    }

    /** A URL's path, decoded — what the listing script stores and compares. */
    public static function pathOf(string $url): string
    {
        return rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
    }
}
