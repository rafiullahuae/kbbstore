<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Store\ProductController;
use App\Models\Product;
use App\Support\AlsoLikePicks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The recommendation blocks at the foot of a product page.  (Lane RP → RP2 → BC)
 *
 * The owner's third plan (Lane BC):
 *
 *   "keep also the tab, more from anua, and more from toner, both tabs. and
 *    remove the second block complete your routine. … give option to disabl
 *    the tabs and show the brand, and then the category block, but by default
 *    keep the tabs on, turn off the 2nd block and third will be continue
 *    shoping which we have already."
 *
 *   1  Brand and category. DEFAULT: one block, two tabs — "More from {brand}"
 *      (best sellers first) | "More {category}" (the most specific category,
 *      in stock first, then best sellers). Both lists are in the HTML, so
 *      both are crawlable and the URL never varies; the closed tab is
 *      [hidden], so its pictures are not fetched. Which tab opens first is
 *      AlsoLikeSettings `tab_first`: by default the one matching the listing
 *      the shopper clicked from (a sessionStorage hint shop.js writes, read
 *      by ymal.js — the server response never varies on it), else the brand.
 *      OPTION (`pair` = blocks): a brand block, then a category block.
 *   2  Best sellers — OFF by default (`best_on`).
 *   3  Continue shopping: the shopper's recently viewed products (the
 *      `kbb_viewed` cookie ProductController::rememberViewed() writes), then
 *      best sellers. A crawler and a first visit carry no cookie and see best
 *      sellers: real, crawlable links.
 *
 * A product's own picks (Catalog → Products → edit → You may also like) lead
 * the best-seller block while it is on, and otherwise follow the recently
 * viewed in Continue shopping — "Only my picks" leaves the best-seller
 * top-up out of whichever block carries them.
 *
 * ── NO PRODUCT TWICE, AND NEVER THE ONE ON THE PAGE ────────────────────────
 *
 * Filled brand → category → best sellers → continue shopping, each from what
 * the ones before it did not take — the category tab included: it leaves out
 * what the brand tab shows, so switching tabs never shows a card twice.
 * "Buy these together" is kept out of everything after the brand, as before.
 * The cached lists are disjoint by construction, and forProduct() takes again
 * against everything already drawn, so a card hidden since the lists were
 * cached cannot open a gap a repeat falls into.
 *
 * ── THE CATEGORY IS THE MOST SPECIFIC ONE ──────────────────────────────────
 *
 * Of the product's categories (the page's own query loads them, with
 * parent_id and depth — no extra query), categoryCandidates() keeps the
 * deepest: never one that is the parent of another of its categories, then
 * the greatest `depth`. Two equally deep ones both go into the ONE union, and
 * the one with more in-stock products besides this one wins, then the lowest
 * id — deterministic, whatever order the pivot rows were written in.
 *
 * ── ▲ TWO QUERIES FOR EVERY BLOCK, COLD OR WARM ────────────────────────────
 *
 *   COLD  ONE `UNION ALL` — the brand, the category, the product's own picks,
 *         the best sellers and the shopper's viewed ids — then the brands,
 *         eager-loaded once.
 *   WARM  The lists are cached per product (TTL below; a saved product
 *         forgets its own, a changed setting changes the fingerprint), so a
 *         repeat view reads ONE `WHERE id IN (…)` — cached ids and the viewed
 *         ids together — then the brands.
 *
 * Visibility and stock are re-applied on the warm read. The viewed ids are
 * never cached: they are the shopper's, not the product's. What each card
 * PRINTS is never served stale: App\Support\CardFragments signs every card.
 */
class ProductRecs
{
    public const CACHE_PREFIX = 'kbb.recs.';

    /*
     * One hour (was 600 s). Which products are suggested may be up to an hour
     * old; what each card prints never is -- CardFragments re-checks price,
     * stock, labels and names on every view. The rebuild costs a few ms CPU
     * once per product per TTL, so a longer TTL keeps the product page at or
     * under its pre-blocks speed (integrator, measured by Lane RP).
     */
    public const TTL = 3600;

    /** The longest list Continue shopping reads from the cookie (rememberViewed keeps 12). */
    public const MAX_VIEWED = 12;

    /** The sessionStorage key the listing script writes. Pinned by a test. */
    public const HINT_KEY = 'kbb_rp_from';

    private const BRAND = 'b';

    private const CATEGORY = 'c';

    private const BEST = 'f';

    private const MANUAL = 'm';

    private const VIEWED = 'v';

    /** Room in the cached lists for what "Buy these together" takes out (it draws ≤ 5). */
    private const SLACK = 6;

    public function __construct(
        private AlsoLikeSettings $settings,
        private ProductSections $sections,
    ) {}

    /** Drop one product's cached lists. Called from Product::booted(). */
    public static function forget(int $productId): void
    {
        if ($productId > 0) {
            Cache::forget(self::CACHE_PREFIX.$productId);
        }
    }

    /**
     * Everything the partials need.
     *
     * @param  list<int>  $onPage  ids already drawn elsewhere on the page ("Buy these together")
     * @return array{brand: array<string, mixed>, category: array<string, mixed>, tabs: ?array<string, mixed>, recent: array<string, mixed>, alsoLike: array<string, mixed>, order: list<string>}
     */
    public function forProduct(Product $product, Request $request, array $onPage = []): array
    {
        $c = $this->settings->all();
        $none = collect();
        $tabsMode = ($c['pair'] ?? 'tabs') !== 'blocks';
        $out = [
            'brand' => ['products' => $none, 'title' => ''],
            'category' => ['products' => $none, 'title' => '', 'id' => null],
            // The tab block, when both tabs have cards: panels, heading, layout.
            'tabs' => null,
            'recent' => ['products' => $none, 'title' => '', 'eyebrow' => '', 'seen' => []],
            // The best-seller block (and, as always, the shared config).
            'alsoLike' => ['products' => $none, 'config' => $c, 'title' => ''],
            'order' => self::order($c),
        ];

        // Sections → "You may also like" off for both devices: the whole foot
        // of the page is off, and NOTHING is asked of the database.
        if ($this->sections->hidden('related')) {
            return $out;
        }

        $brand = $c['brand_on'] && $product->brand_id !== null && $product->relationLoaded('brand') ? $product->brand : null;
        $candidates = $c['cat_on'] ? self::categoryCandidates($product) : [];
        $wantBest = (bool) $c['best_on'];
        $wantRecent = (bool) $c['recent_on'];

        if ($brand === null && $candidates === [] && ! $wantBest && ! $wantRecent) {
            return $out;
        }

        // Each block renders the larger of its two device counts; CSS hides
        // the extras on the device that shows fewer. So the no-repeat rule
        // below runs on what is RENDERED, and a card hidden on a phone can
        // never turn up in another block there.
        $lay = [
            'brand' => AlsoLikeSettings::layoutFor($c, $tabsMode ? 'tabs' : 'brand'),
            'cat' => AlsoLikeSettings::layoutFor($c, $tabsMode ? 'tabs' : 'cat'),
            'recent' => AlsoLikeSettings::layoutFor($c, 'recent'),
            'best' => AlsoLikeSettings::layoutFor($c, 'best'),
        ];
        $n = [
            'b' => $brand !== null ? $lay['brand']['n'] : 0,
            'c' => $candidates === [] ? 0 : $lay['cat']['n'],
            'r' => $wantRecent ? $lay['recent']['n'] : 0,
            'f' => $wantBest ? $lay['best']['n'] : 0,
        ];

        // His picks lead the best-seller block while it is on, and otherwise
        // follow the recently viewed in Continue shopping.
        $host = $wantBest ? 'f' : ($wantRecent ? 'r' : null);
        $picks = $host !== null ? AlsoLikePicks::read($product) : ['mode' => AlsoLikePicks::MODE_RULE, 'ids' => []];
        $usePicks = $picks['ids'] !== [] && $picks['mode'] !== AlsoLikePicks::MODE_RULE;
        $only = $usePicks && $picks['mode'] === AlsoLikePicks::MODE_ONLY;
        // Which blocks top up from the shop's best sellers.
        $topUp = ['f' => $n['f'] > 0 && ! ($only && $host === 'f'), 'r' => $n['r'] > 0 && ! ($only && $host === 'r')];

        $viewed = $wantRecent ? self::viewedIds($request, (int) $product->id) : [];
        [$lists, $categoryId] = $this->lists($product, $c, $n, array_map(fn ($cat) => (int) $cat->id, $candidates),
            $usePicks ? $picks : null, $topUp['f'] || $topUp['r'], $viewed);
        $category = $categoryId === null ? null : collect($candidates)->first(fn ($cat) => (int) $cat->id === $categoryId);
        $out['category']['id'] = $categoryId;

        $taken = [(int) $product->id => true];

        // 1a. The brand. Nothing else on the page is taken out of it, as before.
        $one = self::take($lists[self::BRAND] ?? [], $taken, $n['b']);
        $taken += self::idsOf($one);

        // "Buy these together" stays out of everything below the brand.
        foreach ($onPage as $id) {
            $taken[(int) $id] = true;
        }

        // 1b. The category, without the brand's cards — tab or block.
        $two = self::take($lists[self::CATEGORY] ?? [], $taken, $n['c']);
        $taken += self::idsOf($two);

        $manual = $lists[self::MANUAL] ?? [];

        // 2. Best sellers, his picks first.
        $best = $n['f'] > 0 ? self::take(array_merge($host === 'f' ? $manual : [], $topUp['f'] ? ($lists[self::BEST] ?? []) : []), $taken, $n['f']) : [];
        $taken += self::idsOf($best);

        // 3. Continue shopping: what this shopper viewed, then his picks (when
        // the best-seller block is off), then best sellers.
        $seen = [];
        $recent = [];

        if ($n['r'] > 0) {
            $seen = self::take($lists[self::VIEWED] ?? [], $taken, $n['r']);
            $recent = array_merge($seen, self::take(
                array_merge($host === 'r' ? $manual : [], $topUp['r'] ? ($lists[self::BEST] ?? []) : []),
                $taken + self::idsOf($seen), $n['r'] - count($seen)));
        }

        $ar = ! \App\Support\Locale::isDefault() && \App\Support\Locale::current() === 'ar';
        $typed = fn (string $key): string => trim((string) ($c[$key.($ar ? '_ar' : '')] ?? ''));

        if ($brand !== null && $one !== []) {
            $out['brand'] = [
                'products' => collect($one),
                'layout' => $lay['brand'],
                'title' => $typed('brand_title') !== '' ? $typed('brand_title') : (string) __('store.product.recs_tab_brand', ['brand' => (string) $brand->t('name')]),
                'paths' => [self::pathOf($brand->url())],
            ];
        }

        if ($category !== null && $two !== []) {
            $out['category'] = [
                'products' => collect($two),
                'layout' => $lay['cat'],
                'title' => $typed('cat_title') !== '' ? $typed('cat_title') : (string) __('store.product.recs_tab_category', ['category' => (string) $category->t('name')]),
                'paths' => self::categoryPaths($product),
                'id' => $categoryId,
            ];
        }

        // Two tabs only when both have cards; one alone is drawn as its block.
        if ($tabsMode && $out['brand']['products']->isNotEmpty() && $out['category']['products']->isNotEmpty()) {
            $first = (string) ($c['tab_first'] ?? 'auto');
            $panels = ['brand' => ['key' => 'brand'] + $out['brand'], 'category' => ['key' => 'category'] + $out['category']];
            $out['tabs'] = AlsoLikeSettings::wording($c) + [
                'panels' => array_values($first === 'category' ? array_reverse($panels) : $panels),
                'layout' => $lay['brand'],
                // Only "where the shopper came from" lets the browser switch.
                'hint' => ! in_array($first, ['brand', 'category'], true),
            ];
        }

        if ($best !== []) {
            $out['alsoLike']['products'] = collect($best);
            $out['alsoLike']['layout'] = $lay['best'];
            $out['alsoLike']['title'] = $typed('best_title') !== '' ? $typed('best_title') : (string) __('store.product.recs_best_eyebrow');
        }

        if ($recent !== []) {
            $out['recent'] = [
                'products' => collect($recent),
                'layout' => $lay['recent'],
                'title' => $typed('recent_title') !== '' ? $typed('recent_title') : (string) __('store.product.recs_recent_heading'),
                'eyebrow' => (string) __($seen !== [] ? 'store.product.recs_recent_eyebrow' : 'store.product.recs_best_eyebrow'),
                'seen' => array_keys(self::idsOf($seen)),
            ];
        }

        return $out;
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

    /** A URL's path, decoded — what the listing script stores and compares. */
    public static function pathOf(string $url): string
    {
        return rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
    }

    /**
     * Every listing path that should open the category tab: each of the
     * product's categories and the shelves above it in its path. No query —
     * `path` is on the rows the page loaded.
     *
     * @return list<string>
     */
    private static function categoryPaths(Product $product): array
    {
        $out = [];

        foreach ($product->categories as $cm) {
            $segments = array_values(array_filter(explode('/', trim((string) ($cm->path ?: $cm->slug), '/')), 'strlen'));

            for ($i = count($segments); $i >= 1; $i--) {
                $out[] = self::pathOf(\App\Support\Url::to(
                    \App\Support\UrlScheme::collection(implode('/', array_slice($segments, 0, $i)))
                ));
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The product's most specific categories, from the relation the page
     * already loaded: none that is the parent of another of them, then the
     * greatest depth. Usually one; two only when two are equally deep, and
     * lists() settles those by stock, then id. Sorted by id, so the order the
     * pivot rows were written in cannot matter.
     *
     * @return list<\App\Models\Category>
     */
    public static function categoryCandidates(Product $product): array
    {
        if (! $product->relationLoaded('categories') || $product->categories->isEmpty()) {
            return [];
        }

        $cats = $product->categories->sortBy(fn ($cat) => (int) $cat->id)->values();
        $parents = $cats->pluck('parent_id')->filter()->map(fn ($id) => (int) $id)->flip();
        $leaves = $cats->reject(fn ($cat) => isset($parents[(int) $cat->id]));
        $leaves = $leaves->isEmpty() ? $cats : $leaves; // a cycle: every one is a parent
        $deepest = (int) $leaves->max(fn ($cat) => (int) $cat->getAttribute('depth'));

        return $leaves->filter(fn ($cat) => (int) $cat->getAttribute('depth') === $deepest)->values()->all();
    }

    /**
     * Cards in view, held to their option lists, for both sliders.
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
     * The lists, already disjoint: cached ids + one IN when warm, one union
     * when cold — the shopper's viewed ids ride in the same statement either
     * way, and are never cached. Then the brands, once, for every card.
     *
     *   b  the brand, final                c  the category, without b
     *   m  his picks, in his order         f  best sellers, without b and c
     *   v  the viewed ids, in cookie order (per request)
     *
     * @param  array<string, mixed>  $c
     * @param  array{b: int, c: int, r: int, f: int}  $n
     * @param  list<int>  $categoryIds  categoryCandidates(), by id
     * @param  array{mode: string, ids: list<int>}|null  $picks  null when no block carries them
     * @param  list<int>  $viewed
     * @return array{0: array<string, list<Product>>, 1: ?int} the lists, and the category chosen
     */
    private function lists(Product $product, array $c, array $n, array $categoryIds, ?array $picks, bool $needBest, array $viewed): array
    {
        $fp = md5(json_encode([
            // The cached shape; a new one is never read as an old one.
            5,
            $n, $c['hide_oos'], $picks, $needBest, $product->brand_id, $categoryIds,
        ]) ?: '');

        $key = self::CACHE_PREFIX.(int) $product->id;
        $hit = Cache::get($key);

        if (is_array($hit) && ($hit['fp'] ?? null) === $fp && is_array($hit['ids'] ?? null)) {
            $cat = isset($hit['cat']) && in_array((int) $hit['cat'], $categoryIds, true) ? (int) $hit['cat'] : null;

            return [$this->hydrate($hit['ids'], $viewed, (bool) $c['hide_oos']), $cat];
        }

        $parts = [];

        if ($n['b'] > 0) {
            $parts[] = $this->bestFirst($this->part($product, $c, self::BRAND, $n['b']))
                ->where('products.brand_id', (int) $product->brand_id);
        }

        if ($n['c'] > 0) {
            // In stock first, then best sellers. One SELECT per equally-deep
            // candidate (almost always one), each enough to fill the category
            // after the brand has taken its share.
            foreach ($categoryIds as $id) {
                $parts[] = $this->bestFirst($this->inStockFirst($this->part($product, $c, self::CATEGORY.$id, $n['b'] + $n['c'] + self::SLACK)))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('category_product as rpc')
                        ->whereColumn('rpc.product_id', 'products.id')
                        ->where('rpc.category_id', $id));
            }
        }

        if ($picks !== null) {
            $parts[] = $this->part($product, $c, self::MANUAL, count($picks['ids']))->whereIn('products.id', $picks['ids']);
        }

        if ($needBest) {
            // Enough to fill every block that tops up after the brand and the
            // category have taken theirs — on a best seller's own page that
            // can be the shop's top few dozen.
            $parts[] = $this->inStockFirst($this->part($product, $c, self::BEST, min(120, $n['b'] + $n['c'] + $n['r'] + $n['f'] + 2 * self::SLACK)))
                ->orderByDesc('products.total_sales')->orderByDesc('products.id');
        }

        $cacheable = $parts !== [];

        if ($viewed !== []) {
            $parts[] = $this->part($product, $c, self::VIEWED, count($viewed))->whereIn('products.id', $viewed);
        }

        if ($parts === []) {
            return [[], null];
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

        // Equally deep candidates: the one with more in-stock products besides
        // this one, then the lowest id. Counted from the rows this statement
        // already read, each capped at what the block could use — two that can
        // both fill it are a tie, and the lowest id is the stable answer.
        $categoryId = null;
        $most = -1;

        foreach ($categoryIds as $id) {
            $stocked = count(array_filter($pools[self::CATEGORY.$id] ?? [], fn ($m) => $m->stock_status !== 'outofstock'));

            if ($stocked > $most) {
                [$categoryId, $most] = [$id, $stocked];
            }
        }

        // Disjoint, in fill order, each with room for the per-visit exclusions.
        $skip = [(int) $product->id => true];
        $lists = [self::BRAND => self::take($pools[self::BRAND] ?? [], $skip, $n['b'])];
        $skip += self::idsOf($lists[self::BRAND]);
        $lists[self::CATEGORY] = self::take($categoryId === null ? [] : ($pools[self::CATEGORY.$categoryId] ?? []), $skip, $n['c'] > 0 ? $n['c'] + self::SLACK : 0);
        $skip += self::idsOf($lists[self::CATEGORY]);
        $lists[self::MANUAL] = $picks === null ? [] : self::take(self::inOrder(self::byId($pools[self::MANUAL] ?? []), $picks['ids']), $skip, AlsoLikePicks::MAX);
        $lists[self::BEST] = $needBest ? self::take($pools[self::BEST] ?? [], $skip, $n['r'] + $n['f'] + self::SLACK + count($picks['ids'] ?? [])) : [];

        if ($cacheable) {
            Cache::put($key, ['fp' => $fp, 'cat' => $categoryId, 'ids' => array_map(fn ($list) => array_map(fn ($m) => (int) $m->id, $list), $lists)], self::TTL);
        }

        $lists[self::VIEWED] = self::inOrder(self::byId($pools[self::VIEWED] ?? []), $viewed);

        return [$lists, $categoryId];
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

        $lists = [];

        foreach ($cached as $src => $list) {
            $lists[(string) $src] = self::inOrder($byId, array_map('intval', (array) $list));
        }

        $lists[self::VIEWED] = self::inOrder($byId, $viewed);

        return $lists;
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

    /** Best sellers first, `id` breaking the tie — the brand and category tabs' order, kept. */
    private function bestFirst(Builder $q): Builder
    {
        return $q->orderByDesc('products.total_sales')->orderByDesc('products.id');
    }

    /** In stock first — the best-seller list's order, kept. */
    private function inStockFirst(Builder $q): Builder
    {
        return $q->orderByRaw("CASE WHEN products.stock_status = 'outofstock' THEN 1 ELSE 0 END");
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
}
