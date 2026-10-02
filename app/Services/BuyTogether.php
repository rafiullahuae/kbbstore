<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Store\ProductController;
use App\Models\Product;
use App\Support\ProductViews;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Which products "Buy these together" shows on one product page.   (Lane RB)
 *
 * App\Services\BuyTogetherSettings carries the owner's request; this is the
 * choosing, and it is App\Services\AlsoLikeRail's shape on purpose — the same
 * number of queries, cold or warm, whatever the rule and whatever the count.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────────
 *
 *   1. FIRST, the product on the page. Always — it is what the shopper is
 *      buying. A product that is sold out (or, on a variable product, has no
 *      option left in stock) draws no section at all: a bundle whose first
 *      item cannot be bought is not a bundle.
 *   2. THEN ONE FROM EACH COMPLEMENTARY CATEGORY, in the order the category
 *      pairs list them (App\Services\BuyTogetherPairs — Sunscreens →
 *      Moisturizers, Toners, Cleansing oils, Face masks), each chosen by the
 *      rule he picked: best sellers, random, most viewed, newest, top rated.
 *   3. A SECOND ROUND over the same categories when the count asks for more
 *      products than there are categories.
 *   4. THE SHOP'S BEST SELLERS for whatever is still missing — a category with
 *      nothing in it, or a product with no pairing at all.
 *
 *   Never the same product twice, never the product on the page twice, never a
 *   hidden, draft, scheduled or deleted product. A companion that needs an
 *   option chosen (a variable product) is never offered: ticking it would put
 *   an option in the basket the shopper never saw, so it is left out rather
 *   than guessed. Sold-out companions are left out unless "Hide sold-out
 *   products" is off, when they are drawn greyed with no tick.
 *
 * ── ▲ TWO QUERIES ───────────────────────────────────────────────────────────
 *
 *   COLD  ONE `UNION ALL` of small SELECTs — a pool of up to POOL products per
 *         complementary category, each with the rule's own ORDER BY and LIMIT,
 *         and the shop's best sellers — then the brands, eager-loaded. The
 *         rows ARE the cards (ProductController::CARD_COLUMNS).
 *   WARM  The POOLS (ids, per slot) are cached per product for ten minutes, so
 *         a repeat view reads ONE `WHERE id IN (…)` for the cards, then the
 *         brands. Visibility and stock are re-applied on that read, so a
 *         product hidden or sold out since leaves on the next view.
 *
 * The PICK happens in PHP on every request, from the pools. That is what makes
 * Random "a different pick on every visit" without a query per visit, and it is
 * why the cache holds candidates rather than the answer.
 *
 * (The category list is one more query when ITS cache is cold, shared by every
 * product page: BuyTogetherPairs::categories().)
 */
class BuyTogether
{
    public const CACHE_PREFIX = 'kbb.bt.';

    public const TTL = 600;

    /** Candidates kept per complementary category. */
    public const POOL = 6;

    /** Best sellers kept for the top-up. */
    public const FALLBACK_POOL = 12;

    public function __construct(
        private BuyTogetherSettings $settings,
        private BuyTogetherPairs $pairs,
        private ProductSections $sections,
    ) {}

    /** Drop one product's cached pools. Called from Product::booted(). */
    public static function forget(int $productId): void
    {
        if ($productId > 0) {
            Cache::forget(self::CACHE_PREFIX.$productId);
        }
    }

    /**
     * Everything the partial needs, or `products` empty when the section is not
     * drawn — in which case NOTHING was asked of the database.
     *
     * @return array{products: Collection<int, Product>, config: array<string, mixed>, heading: string}
     */
    public function forProduct(Product $product): array
    {
        $c = $this->settings->all();
        $out = ['products' => collect(), 'config' => $c, 'heading' => ''];

        if (! $c['on'] || $this->sections->hidden('fbt')) {
            return $out;
        }

        if (! $this->mainIsBuyable($product)) {
            return $out;
        }

        $mates = $this->companions($product, $c);

        if ($mates->isEmpty()) {
            return $out;
        }

        $out['products'] = collect([$product])->concat($mates)->values();
        $out['heading'] = BuyTogetherSettings::heading($c);

        return $out;
    }

    /**
     * The product on the page can be bought: a simple product in stock, or a
     * variable product with at least one option in stock (its variants are
     * already loaded by the product page, so this costs nothing there).
     */
    public function mainIsBuyable(Product $product): bool
    {
        if (! $product->requiresVariant()) {
            return $product->stock_status === 'instock';
        }

        return $this->mainVariant($product) !== null;
    }

    /**
     * The option a variable product's page starts on: the first one actually
     * on the shelf — the same expression store/product.blade.php uses for
     * `$buyable`, so the card and the buy box agree before anything is pressed.
     */
    public function mainVariant(Product $product): ?\App\Models\ProductVariant
    {
        if (! $product->requiresVariant()) {
            return null;
        }

        $variants = $product->relationLoaded('variants') ? $product->variants : $product->variants()->orderBy('position')->get();

        return $variants->first(fn ($v) => $v->inStock());
    }

    /**
     * THE CARD SIZE IS THE CART RAIL'S, read from the cart page's own settings.
     *
     * He asked for "the same carousel, same size" as Recommended for you, and
     * that rail's card is not a pixel width: it is a FRACTION of its rail —
     * `(100% - 14px - 7px × (n - 1)) / n` with n = "Products across the screen"
     * (4.5 shipped) on a phone, and on a laptop n = "Products across the rail"
     * (5.5) of a rail that is the cart's left column. So this hands the product
     * page the same n, and on a laptop the same column width, as arithmetic
     * over the cart page's own numbers — change the cart rail and this one
     * follows it. Nothing is measured.
     *
     * Every value is an integer the CartPage schema already clamped; the
     * string is printed into a style attribute through Blade's escaper.
     */
    public static function cardStyle(): string
    {
        $c = app(CartPage::class)->all();
        $per = number_format(max(1, (int) $c['rec_per']) / 10, 2, '.', '');
        $out = [
            '--cpg-per:'.$per,
            // The rail's own type knobs (Appearance → Cart page → Recommended),
            // printed exactly as CartPage::cssVariables() prints them.
            '--cpg-rec-bold:'.(! empty($c['rec_bold']) ? 600 : 400),
            '--cpg-rec-price-bold:'.(! empty($c['rec_price_bold']) ? 600 : 400),
            '--cpg-rec-lh:'.number_format((int) $c['rec_lh'] / 100, 2, '.', ''),
            '--cpg-rec-gap:'.(int) $c['rec_gap'].'px',
            '--cpg-rec-img-gap:'.(int) $c['rec_img_gap'].'px',
        ];

        if (! empty($c['d_on'])) {
            $dper = max(1, (int) $c['d_rec_per']) / 10;
            // The cart's left column, less its section padding and the rail's
            // own 14px either side, less the formula's 14px and its gaps.
            $fixed = 2 * (int) $c['d_pad_x'] + (int) $c['d_aside'] + (int) $c['d_gap']
                + 2 * (int) $c['d_sec_pad'] + 28 + 14 + 7 * ($dper - 1);
            $out[] = '--bt-d-card:calc((min(100vw, '.(int) $c['d_max'].'px) - '
                .number_format($fixed, 2, '.', '').'px) / '.number_format($dper, 2, '.', '').')';
        }

        return implode(';', $out);
    }

    /**
     * The companions, in the order the section draws them.
     *
     * @param  array<string, mixed>  $c
     * @return Collection<int, Product>
     */
    public function companions(Product $product, array $c): Collection
    {
        $slots = $this->slots($product);
        $fingerprint = md5(json_encode([
            $c['rule'], $c['count'], $c['hide_oos'], $c['same_brand'],
            $slots, $product->brand_id === null ? null : (int) $product->brand_id,
        ]) ?: '');
        $key = self::CACHE_PREFIX.(int) $product->id;

        $hit = Cache::get($key);

        if (is_array($hit) && ($hit['fp'] ?? null) === $fingerprint && is_array($hit['pools'] ?? null)) {
            $pools = $hit['pools'];
            $rows = $this->hydrate($pools, $c);
        } else {
            $rows = $this->cold($product, $c, $slots);
            $pools = [];

            foreach ($rows as $row) {
                $pools[(string) $row->getAttribute('bt_src')][] = (int) $row->id;
            }

            Cache::put($key, ['fp' => $fingerprint, 'pools' => $pools], self::TTL);
        }

        $chosen = $this->pick($product, $c, $slots, $pools, $rows->keyBy(fn ($p) => (int) $p->id));

        \App\Support\SetPricing::prime($chosen);

        return $chosen;
    }

    /**
     * Which of these products this product's section could have offered.
     *                                                                (Lane RE)
     *
     * The bundle discount is only for a bundle the SECTION made, so
     * Store\CartController::addTogether() asks this before it groups anything.
     * A request naming any five products in the shop and the page of a sixth
     * gets them added — they are on sale — but not grouped, so not discounted.
     *
     * A companion counts when it is visible, bought as it stands (not a
     * variable product), and is one the section draws from, by any of the
     * three routes the section itself takes:
     *
     *   - it is on one of the main product's complementary shelves (slots());
     *   - it is among the shop's best sellers that top a short section up;
     *   - it is in the pools cached for this page right now.
     *
     * The first is what makes "Random — a different pick on every visit"
     * work: the pick a shopper saw is not reproducible, but its shelf is. The
     * rule (best, newest, most viewed…) only ORDERS a shelf, so any product on
     * it is a product the section can show.
     *
     * Two queries at most, whatever the number asked about.
     *
     * @param  list<int>  $ids
     * @return list<int>  the ids that may be grouped with $main
     */
    public function allowedCompanions(Product $main, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id) => $id > 0 && $id !== (int) $main->id,
        )));

        if ($ids === []) {
            return [];
        }

        $c = $this->settings->all();
        $allowed = [];

        $hit = Cache::get(self::CACHE_PREFIX.(int) $main->id);

        if (is_array($hit) && is_array($hit['pools'] ?? null)) {
            foreach ($hit['pools'] as $pool) {
                foreach ((array) $pool as $id) {
                    if (in_array((int) $id, $ids, true)) {
                        $allowed[(int) $id] = true;
                    }
                }
            }
        }

        $slots = $this->slots($main);
        $rest = array_values(array_diff($ids, array_keys($allowed)));

        if ($rest !== [] && $slots !== []) {
            $q = Product::query()->visible()
                ->whereIn('products.id', $rest)
                ->where(fn ($w) => $w->whereNull('products.type')->orWhere('products.type', '!=', 'variable'))
                ->where(fn ($w) => $w->whereIn('products.category_id', $slots)
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('category_product as btc')
                        ->whereColumn('btc.product_id', 'products.id')
                        ->whereIn('btc.category_id', $slots)));

            foreach ($q->pluck('products.id') as $id) {
                $allowed[(int) $id] = true;
            }
        }

        $rest = array_values(array_diff($ids, array_keys($allowed)));

        if ($rest !== []) {
            $best = $this->part($main, $c, 'f', self::FALLBACK_POOL)
                ->orderByDesc('products.total_sales')->orderByDesc('products.id')
                ->get()->pluck('id')->map(fn ($i) => (int) $i)->all();

            foreach ($rest as $id) {
                if (in_array($id, $best, true)) {
                    $allowed[$id] = true;
                }
            }
        }

        return array_values(array_filter($ids, fn (int $id) => isset($allowed[$id])));
    }

    /**
     * The complementary categories this product's section draws from.
     *
     * @return list<int>
     */
    public function slots(Product $product): array
    {
        $anchor = $this->pairs->anchorFor($product);

        return $anchor === null ? [] : $this->pairs->pairsFor($anchor);
    }

    /**
     * Choose, from the pools, in his order: one per category, a second round,
     * then the best sellers. Random shuffles each pool on every call.
     *
     * @param  array<string, mixed>  $c
     * @param  list<int>  $slots
     * @param  array<string, list<int>>  $pools
     * @param  Collection<int, Product>  $byId
     * @return Collection<int, Product>
     */
    public function pick(Product $product, array $c, array $slots, array $pools, Collection $byId): Collection
    {
        $want = max(2, (int) $c['count']) - 1;
        $taken = [(int) $product->id => true];
        $out = [];
        $random = $c['rule'] === 'random';

        $order = [];

        foreach ($slots as $i => $_) {
            $ids = array_values(array_map('intval', $pools['c'.$i] ?? []));

            if ($random) {
                shuffle($ids);
            }

            $order[$i] = $ids;
        }

        $takeFrom = function (array $ids) use (&$taken, &$out, $byId): bool {
            foreach ($ids as $id) {
                if (! isset($taken[$id]) && $byId->has($id)) {
                    $taken[$id] = true;
                    $out[] = $byId[$id];

                    return true;
                }
            }

            return false;
        };

        for ($round = 0; $round < 2 && count($out) < $want; $round++) {
            foreach ($order as $ids) {
                if (count($out) >= $want) {
                    break;
                }

                $takeFrom($ids);
            }
        }

        $fallback = array_values(array_map('intval', $pools['f'] ?? []));

        while (count($out) < $want && $takeFrom($fallback)) {
            // one at a time, best seller first
        }

        return collect($out);
    }

    /**
     * The cold path: one union, then the brands.
     *
     * @param  array<string, mixed>  $c
     * @param  list<int>  $slots
     * @return Collection<int, Product>
     */
    public function cold(Product $product, array $c, array $slots): Collection
    {
        $parts = [];

        foreach ($slots as $i => $categoryId) {
            $q = $this->part($product, $c, 'c'.$i, self::POOL)
                ->where(fn ($w) => $w->where('products.category_id', $categoryId)
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('category_product as btc')
                        ->whereColumn('btc.product_id', 'products.id')
                        ->where('btc.category_id', $categoryId)));

            $parts[] = $this->ordered($q, $c, $product);
        }

        // The top-up: the shop's best sellers, whatever the rule.
        $parts[] = $this->part($product, $c, 'f', self::FALLBACK_POOL)
            ->orderByDesc('products.total_sales')->orderByDesc('products.id');

        $query = array_shift($parts);

        foreach ($parts as $part) {
            $query->unionAll($part->toBase());
        }

        $rows = $query->get();
        $rows->load('brand:id,name,slug');

        return $rows;
    }

    /**
     * The warm path: every pooled id, re-checked for visibility and stock.
     *
     * @param  array<string, list<int>>  $pools
     * @param  array<string, mixed>  $c
     * @return Collection<int, Product>
     */
    private function hydrate(array $pools, array $c): Collection
    {
        $ids = array_values(array_unique(array_map('intval', array_merge(...array_values($pools ?: [[]])))));

        if ($ids === []) {
            return collect();
        }

        $q = Product::query()
            ->select(ProductController::CARD_COLUMNS)
            ->visible()
            ->whereIn('id', $ids)
            ->where(fn ($w) => $w->whereNull('type')->orWhere('type', '!=', 'variable'))
            ->with('brand:id,name,slug');

        if ($c['hide_oos']) {
            $q->where('stock_status', 'instock');
        }

        return $q->get();
    }

    /** One SELECT of the union: the card's columns, tagged with its slot. */
    private function part(Product $product, array $c, string $src, int $limit): Builder
    {
        $columns = array_map(fn ($col) => 'products.'.$col, ProductController::CARD_COLUMNS);

        $q = Product::query()
            ->select($columns)
            ->selectRaw("'{$src}' as bt_src")
            ->visible()
            ->where('products.id', '!=', (int) $product->id)
            // A companion is bought as it stands: nothing to choose.
            ->where(fn ($w) => $w->whereNull('products.type')->orWhere('products.type', '!=', 'variable'))
            ->limit(max(1, $limit));

        if ($c['hide_oos']) {
            $q->where('products.stock_status', 'instock');
        }

        return $q;
    }

    /** The rule's ORDER BY, after "same brand first" when that is on. */
    private function ordered(Builder $q, array $c, Product $product): Builder
    {
        if ($c['same_brand'] && $product->brand_id !== null) {
            $q->orderByRaw('CASE WHEN products.brand_id = ? THEN 0 ELSE 1 END', [(int) $product->brand_id]);
        }

        match ((string) $c['rule']) {
            'random' => $q->inRandomOrder(),
            'viewed' => $q->orderByDesc(ProductViews::windowSum())
                ->orderByDesc('products.total_sales')->orderByDesc('products.id'),
            'newest' => $q->orderByDesc('products.created_at')->orderByDesc('products.id'),
            'rated' => $q->orderByDesc('products.rating')->orderByDesc('products.review_count')
                ->orderByDesc('products.total_sales')->orderByDesc('products.id'),
            default => $q->orderByDesc('products.total_sales')->orderByDesc('products.id'),
        };

        return $q;
    }
}
