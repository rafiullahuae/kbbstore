<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Store\ProductController;
use App\Models\Product;
use App\Support\AlsoLikePicks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The three recommendation blocks at the foot of a product page. (Lane RP2)
 *
 * The owner, changing the plan Lane RP shipped in 2.60.428:
 *
 *   "first block will be brand, if user visit the anua product, then firt
 *    block will display the products from the anua brand. and 2nd block will
 *    be category, if the product is from the toner category, then the 2nd
 *    block will pick the products from that category. and 3rd block will be
 *    You may also like but the proudcts will be picked as best sellers. and
 *    also make sure no any repeat product should be there in all 3 blocks,
 *    cross wise repeat also should not."
 *
 *   1  slider  "More from {brand}"   this product's brand, best sellers first
 *                                    (the brand tab's order, unchanged)
 *   2  grid    "More {category}"     the category the breadcrumb names, in
 *                                    stock first then best sellers (the grid
 *                                    in this position always put stock first),
 *                                    minus block 1
 *   3  slider  "You may also like"   the shop's best sellers, in stock first,
 *                                    minus blocks 1 and 2; a product's own
 *                                    picks (Catalog → Products → edit) lead it
 *
 * ── NO PRODUCT TWICE, AND NEVER THE ONE ON THE PAGE ────────────────────────
 *
 * Filled in that order, each from what the blocks before it did not take. The
 * cached lists are disjoint by construction, and forProduct() takes again
 * against everything already drawn, so a card hidden since the lists were
 * cached cannot open a gap a repeat falls into. "Buy these together" is kept
 * out of blocks 2 and 3, exactly as it was before this change.
 *
 * ── THE CATEGORY IS THE BREADCRUMB'S ───────────────────────────────────────
 *
 * Both breadcrumbs on the page — the visible one in store/product.blade.php
 * and the BreadcrumbList in ProductController::breadcrumbTrail() — print
 * `$product->categories->first()`: the first of the categories the page's
 * own query loaded. Block 2 reads exactly that (breadcrumbCategory()), so its
 * heading and the crumb above the title always name the same shelf.
 *
 * ── ▲ TWO QUERIES FOR ALL THREE, COLD OR WARM ──────────────────────────────
 *
 *   COLD  ONE `UNION ALL` — the brand, the category, the best sellers and the
 *         product's own picks — then the brands, eager-loaded once.
 *   WARM  The three final lists are cached per product (TTL below; a saved
 *         product forgets its own, a changed setting changes the fingerprint),
 *         so a repeat view reads ONE `WHERE id IN (…)`, then the brands.
 *
 * Visibility and stock are re-applied on the warm read. What each card PRINTS
 * is never served stale: App\Support\CardFragments signs every card.
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

    private const BRAND = 'b';

    private const CATEGORY = 'c';

    private const BEST = 'f';

    private const MANUAL = 'm';

    /** Room in blocks 2 and 3's cached lists for what "Buy these together" takes out (it draws ≤ 5). */
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
     * Everything the three partials need.
     *
     * @param  list<int>  $onPage  ids already drawn elsewhere on the page ("Buy these together")
     * @return array{brand: array<string, mixed>, category: array<string, mixed>, alsoLike: array<string, mixed>, order: list<string>}
     */
    public function forProduct(Product $product, array $onPage = []): array
    {
        $c = $this->settings->all();
        $none = collect();
        $out = [
            'brand' => ['products' => $none, 'title' => ''],
            'category' => ['products' => $none, 'title' => ''],
            'alsoLike' => ['products' => $none, 'config' => $c, 'wording' => AlsoLikeSettings::wording($c)],
            'order' => self::order($c),
        ];

        // Sections → "You may also like" off for both devices: the whole foot
        // of the page is off, and NOTHING is asked of the database.
        if ($this->sections->hidden('related')) {
            return $out;
        }

        $brand = $c['brand_on'] && $product->brand_id !== null && $product->relationLoaded('brand') ? $product->brand : null;
        $category = $c['cat_on'] ? self::breadcrumbCategory($product) : null;
        $also = (bool) $c['enabled'];

        if ($brand === null && $category === null && ! $also) {
            return $out;
        }

        // Each block renders the larger of its two device counts; CSS hides
        // the extras on the device that shows fewer. So the no-repeat rule
        // below runs on what is RENDERED, and a card hidden on a phone can
        // never turn up in another block there.
        $lay = [
            'brand' => AlsoLikeSettings::layoutFor($c, 'brand'),
            'cat' => AlsoLikeSettings::layoutFor($c, 'cat'),
            'also' => AlsoLikeSettings::layoutFor($c, 'also'),
        ];
        $picks = $also ? AlsoLikePicks::read($product) : ['mode' => AlsoLikePicks::MODE_RULE, 'ids' => []];
        $lists = $this->lists($product, $c, $brand !== null ? $lay['brand']['n'] : 0, $category === null ? null : (int) $category->id,
            $category === null ? 0 : $lay['cat']['n'], $also ? $lay['also']['n'] : 0, $picks);

        $taken = [(int) $product->id => true];

        // 1. The brand. Nothing else on the page is taken out of it, as before.
        $one = self::take($lists[self::BRAND] ?? [], $taken, $lay['brand']['n']);
        $taken += self::idsOf($one);

        // "Buy these together" stays out of blocks 2 and 3, as it did before.
        foreach ($onPage as $id) {
            $taken[(int) $id] = true;
        }

        // 2. The category, without block 1.
        $two = self::take($lists[self::CATEGORY] ?? [], $taken, $lay['cat']['n']);
        $taken += self::idsOf($two);

        // 3. Best sellers (his picks first), without blocks 1 and 2.
        $three = self::take($lists[self::BEST] ?? [], $taken, $lay['also']['n']);

        $ar = ! \App\Support\Locale::isDefault() && \App\Support\Locale::current() === 'ar';

        if ($brand !== null && $one !== []) {
            $typed = trim((string) ($c[$ar ? 'brand_title_ar' : 'brand_title'] ?? ''));
            $out['brand'] = [
                'products' => collect($one),
                'layout' => $lay['brand'],
                'title' => $typed !== '' ? $typed : (string) __('store.product.recs_tab_brand', ['brand' => (string) $brand->t('name')]),
            ];
        }

        if ($category !== null && $two !== []) {
            $typed = trim((string) ($c[$ar ? 'cat_title_ar' : 'cat_title'] ?? ''));
            $out['category'] = [
                'products' => collect($two),
                'layout' => $lay['cat'],
                'title' => $typed !== '' ? $typed : (string) __('store.product.recs_tab_category', ['category' => (string) $category->t('name')]),
            ];
        }

        $out['alsoLike']['products'] = collect($three);
        $out['alsoLike']['layout'] = $lay['also'];

        return $out;
    }

    /**
     * The category both breadcrumbs print: the first the page's own query
     * loaded. Null when the product has none — block 2 is then not drawn.
     */
    public static function breadcrumbCategory(Product $product): ?\App\Models\Category
    {
        return $product->relationLoaded('categories') ? $product->categories->first() : null;
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
     * The three lists, already disjoint: cached ids + one IN when warm, one
     * union when cold. Either way the brands are loaded once, for every card.
     *
     * @param  array<string, mixed>  $c
     * @param  array{mode: string, ids: list<int>}  $picks
     * @return array<string, list<Product>>
     */
    private function lists(Product $product, array $c, int $n1, ?int $categoryId, int $n2, int $n3, array $picks): array
    {
        $fp = md5(json_encode([
            // The cached shape; a new one is never read as an old one.
            3,
            $n1, $n2, $n3, $c['hide_oos'], $picks, $product->brand_id, $categoryId,
        ]) ?: '');

        $key = self::CACHE_PREFIX.(int) $product->id;
        $hit = Cache::get($key);

        if (is_array($hit) && ($hit['fp'] ?? null) === $fp && is_array($hit['ids'] ?? null)) {
            return $this->hydrate($hit['ids'], (bool) $c['hide_oos']);
        }

        $parts = [];

        if ($n1 > 0) {
            $parts[] = $this->bestFirst($this->part($product, $c, self::BRAND, $n1))
                ->where('products.brand_id', (int) $product->brand_id);
        }

        if ($n2 > 0) {
            // Enough to fill the grid after block 1 has taken its share of it.
            // In stock first, as the grid in this position always ordered.
            $parts[] = $this->bestFirst($this->inStockFirst($this->part($product, $c, self::CATEGORY, $n1 + $n2 + self::SLACK)))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('category_product as rpc')
                    ->whereColumn('rpc.product_id', 'products.id')
                    ->where('rpc.category_id', $categoryId));
        }

        if ($n3 > 0) {
            if ($picks['ids'] !== [] && $picks['mode'] !== AlsoLikePicks::MODE_RULE) {
                $parts[] = $this->part($product, $c, self::MANUAL, count($picks['ids']))->whereIn('products.id', $picks['ids']);
            }

            if ($picks['mode'] !== AlsoLikePicks::MODE_ONLY || $picks['ids'] === []) {
                // Enough to fill block 3 after blocks 1 and 2 have taken theirs —
                // on a best seller's own page that can be the shop's top two dozen.
                $parts[] = $this->inStockFirst($this->part($product, $c, self::BEST, $n1 + $n2 + $n3 + 2 * self::SLACK))
                    ->orderByDesc('products.total_sales')->orderByDesc('products.id');
            }
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

        // Disjoint, in fill order, each with room for the per-visit exclusions.
        $skip = [(int) $product->id => true];
        $lists = [self::BRAND => self::take($pools[self::BRAND] ?? [], $skip, $n1)];
        $skip += self::idsOf($lists[self::BRAND]);
        $lists[self::CATEGORY] = self::take($pools[self::CATEGORY] ?? [], $skip, $n2 > 0 ? $n2 + self::SLACK : 0);
        $skip += self::idsOf($lists[self::CATEGORY]);
        $lead = self::inOrder(self::byId($pools[self::MANUAL] ?? []), $picks['ids']);
        $lists[self::BEST] = self::take(array_merge($lead, $pools[self::BEST] ?? []), $skip, $n3 > 0 ? $n3 + self::SLACK : 0);

        Cache::put($key, ['fp' => $fp, 'ids' => array_map(fn ($list) => array_map(fn ($m) => (int) $m->id, $list), $lists)], self::TTL);

        return $lists;
    }

    /**
     * The warm path: every cached id in ONE statement, visibility and stock
     * re-applied, then the brands.
     *
     * @param  array<string, list<int>>  $cached
     * @return array<string, list<Product>>
     */
    private function hydrate(array $cached, bool $hideOos): array
    {
        $all = [];

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
