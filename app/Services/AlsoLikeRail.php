<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Store\ProductController;
use App\Models\Product;
use App\Support\AlsoLikePicks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Which products the "You may also like" carousel shows.          (Lane PS)
 *
 * App\Services\AlsoLikeSettings carries the owner's request and what ships on.
 * This class is the choosing, and it is written around one number.
 *
 * ── ▲ TWO QUERIES, WHATEVER THE RULE AND WHATEVER THE COUNT ────────────────
 *
 * The section used to cost two statements — four cards from the product's
 * categories, then their brands. It costs two now, cold or warm, for twelve
 * cards or twenty-four, for any rule:
 *
 *   COLD  ONE `UNION ALL` of up to five small SELECTs — manual picks, same
 *         brand, same category, the category tree around it, best sellers —
 *         each with its own ORDER BY and LIMIT and each tagged with the source
 *         it answers for. The rows ARE the cards: the card columns
 *         (Store\ProductController::CARD_COLUMNS) are selected directly, so
 *         nothing is fetched twice. Then the brands, eager-loaded.
 *
 *   WARM  The chosen ids are cached per product, so a repeat view reads ONE
 *         `WHERE id IN (…)` for the cards, then the brands.
 *
 * A set on the rail adds SetPricing::prime()'s one grouped statement and only
 * then — prime() looks first and costs nothing for a rail with no set on it.
 * StorefrontQueryBudgetTest's product page ceiling did not move.
 *
 * The interleaving, the de-duplication and the top-up happen in PHP over at
 * most ~84 short rows. Doing them in SQL is possible and would be a window
 * function per source on two engines that spell them differently.
 *
 * ── THE CACHE, AND WHAT IT CANNOT GET WRONG ────────────────────────────────
 *
 * `kbb.ymal.{product id}` holds the ordered ids and a fingerprint of every
 * setting and pick that chose them. TTL ten minutes. Saving the product
 * forgets it (Product::booted()), and a changed setting or pick changes the
 * fingerprint, so neither waits for the TTL.
 *
 * What it caches is the CHOICE, never the visibility: the warm read re-applies
 * visible() and the out-of-stock rule, so a product hidden, unpublished,
 * deleted or sold out leaves every carousel on the next view rather than ten
 * minutes later. The TTL only decides how soon a NEW product can join.
 */
class AlsoLikeRail
{
    public const CACHE_PREFIX = 'kbb.ymal.';

    public const TTL = 600;

    /** Source tags, carried on each row of the union as `ymal_src`. */
    private const MANUAL = 'm';

    private const BRAND = 'b';

    private const CATEGORY = 'c';

    private const TREE = 'p';

    private const BEST = 'f';

    private const RULE = 'g';

    public function __construct(
        private AlsoLikeSettings $settings,
        private ProductSections $sections,
    ) {}

    /**
     * Everything the partial needs, or an empty `products` when the section is
     * not drawn — in which case NOTHING was asked of the database.
     *
     * @return array{products: Collection<int, Product>, config: array<string, mixed>, wording: array{title: string, eyebrow: string}}
     */
    public function forProduct(Product $product): array
    {
        $c = $this->settings->all();
        $out = ['products' => collect(), 'config' => $c, 'wording' => AlsoLikeSettings::wording($c)];

        if (! $c['enabled'] || $this->sections->hidden('related')) {
            return $out;
        }

        $out['products'] = $this->products($product, $c);

        return $out;
    }

    /** Drop one product's cached choice. Called from Product::booted(). */
    public static function forget(int $productId): void
    {
        if ($productId > 0) {
            Cache::forget(self::CACHE_PREFIX.$productId);
        }
    }

    /**
     * @param  array<string, mixed>  $c
     * @return Collection<int, Product>
     */
    public function products(Product $product, array $c): Collection
    {
        $picks = AlsoLikePicks::read($product);
        $categoryIds = $this->categoryIds($product);
        $fingerprint = $this->fingerprint($product, $c, $picks, $categoryIds);
        $key = self::CACHE_PREFIX.(int) $product->id;

        $hit = Cache::get($key);

        if (is_array($hit) && ($hit['fp'] ?? null) === $fingerprint && is_array($hit['ids'] ?? null)) {
            return $this->hydrate(array_map('intval', $hit['ids']), $c);
        }

        $chosen = $this->choose($product, $c, $picks, $categoryIds);

        Cache::put($key, ['fp' => $fingerprint, 'ids' => $chosen->pluck('id')->map(fn ($i) => (int) $i)->all()], self::TTL);

        \App\Support\SetPricing::prime($chosen);

        return $chosen;
    }

    /**
     * The cold path: one union, then the brands.
     *
     * @param  array<string, mixed>  $c
     * @param  array{mode: string, ids: list<int>}  $picks
     * @param  list<int>  $categoryIds
     * @return Collection<int, Product>
     */
    public function choose(Product $product, array $c, array $picks, array $categoryIds): Collection
    {
        $count = (int) $c['count'];
        $rule = (string) $c['rule'];
        $fill = (bool) $c['fill'];
        $manualOnly = $rule === 'manual' || $picks['mode'] === AlsoLikePicks::MODE_ONLY;
        $withManual = $picks['ids'] !== [] && ($manualOnly || $picks['mode'] === AlsoLikePicks::MODE_FIRST);

        $brandId = $product->brand_id === null ? null : (int) $product->brand_id;

        $parts = [];

        if ($withManual) {
            $parts[] = $this->part($product, $c, self::MANUAL, count($picks['ids']))->whereIn('products.id', $picks['ids']);
        }

        if (! $manualOnly) {
            $wantBrand = in_array($rule, ['mix', 'brand'], true);
            $wantCategory = $rule === 'mix' || $rule === 'category' || ($rule === 'brand' && $fill);
            $wantTree = $fill && in_array($rule, ['mix', 'brand', 'category'], true);
            $wantBest = $fill && $rule !== 'best';

            if ($wantBrand && $brandId !== null) {
                $parts[] = $this->bestFirst($this->part($product, $c, self::BRAND, $count))
                    ->where('products.brand_id', $brandId);
            }

            if ($wantCategory && $categoryIds !== []) {
                $parts[] = $this->bestFirst($this->part($product, $c, self::CATEGORY, $count))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('category_product as ymc')
                        ->whereColumn('ymc.product_id', 'products.id')
                        ->whereIn('ymc.category_id', $categoryIds));
            }

            if ($wantTree && $categoryIds !== []) {
                /*
                 * THE CATEGORY TREE AROUND THIS PRODUCT: the parent of each of
                 * its categories, and that parent's other children. A toner
                 * filed under Skincare › Toners is topped up from Skincare and
                 * from Skincare's other shelves before the whole shop's best
                 * sellers are reached. Subqueries, so it is still ONE statement.
                 */
                $parents = fn ($q) => $q->select('ymp.parent_id')->from('categories as ymp')
                    ->whereIn('ymp.id', $categoryIds)->whereNotNull('ymp.parent_id');

                $parts[] = $this->bestFirst($this->part($product, $c, self::TREE, $count))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('category_product as ymt')
                        ->whereColumn('ymt.product_id', 'products.id')
                        ->where(fn ($w) => $w->whereIn('ymt.category_id', $parents)
                            ->orWhereIn('ymt.category_id', fn ($s) => $s->select('ymk.id')->from('categories as ymk')
                                ->whereIn('ymk.parent_id', $parents))));
            }

            if (in_array($rule, ['best', 'newest', 'sale'], true)) {
                $g = $this->part($product, $c, self::RULE, $count);

                match ($rule) {
                    'best' => $this->bestFirst($g),
                    // The /new-in collection's own ORDER BY.
                    'newest' => $g->orderByDesc('products.created_at')->orderByDesc('products.id'),
                    // The /super-sale collection's own filter and ORDER BY.
                    'sale' => $g->whereNotNull('products.sale_price')
                        ->where('products.sale_price', '>', 0)
                        ->whereColumn('products.sale_price', '<', 'products.price')
                        ->orderByRaw('(products.price - products.sale_price) / products.price DESC')
                        ->orderByDesc('products.id'),
                };

                $parts[] = $g;
            }

            if ($wantBest) {
                // Twice the count: everything already chosen may be among the
                // shop's best sellers, and the top-up still has to reach $count.
                $parts[] = $this->bestFirst($this->part($product, $c, self::BEST, $count * 2));
            }
        }

        if ($parts === []) {
            return collect();
        }

        $query = array_shift($parts);

        foreach ($parts as $part) {
            $query->unionAll($part->toBase());
        }

        $rows = $query->get();
        $rows->load('brand:id,name,slug');

        return $this->assemble($rows, $c, $picks, $manualOnly, $withManual);
    }

    /**
     * Interleave, de-duplicate, top up and cut.
     *
     * @param  Collection<int, Product>  $rows
     * @param  array<string, mixed>  $c
     * @param  array{mode: string, ids: list<int>}  $picks
     * @return Collection<int, Product>
     */
    private function assemble(Collection $rows, array $c, array $picks, bool $manualOnly, bool $withManual): Collection
    {
        $pool = [];

        foreach ($rows as $row) {
            $pool[(string) $row->getAttribute('ymal_src')][] = $row;
        }

        $out = [];
        $add = function (Product $p) use (&$out): void {
            $out[(int) $p->id] ??= $p;
        };

        if ($withManual) {
            // The owner's order, not the database's.
            $byId = collect($pool[self::MANUAL] ?? [])->keyBy(fn ($p) => (int) $p->id);

            foreach ($picks['ids'] as $id) {
                if ($byId->has($id)) {
                    $add($byId[$id]);
                }
            }
        }

        if (! $manualOnly) {
            if ((string) $c['rule'] === 'mix') {
                [$a, $b] = array_map('intval', explode(':', (string) $c['mix']) + [1, 1]);
                $this->interleave($pool[self::BRAND] ?? [], $pool[self::CATEGORY] ?? [], max(1, $a), max(1, $b), $add);
            }

            foreach ([self::BRAND, self::CATEGORY, self::RULE, self::TREE, self::BEST] as $src) {
                foreach ($pool[$src] ?? [] as $p) {
                    $add($p);
                }
            }
        }

        return collect(array_values($out))->take((int) $c['count'])->values();
    }

    /**
     * $a from the first list, $b from the second, in turn, until both run out.
     *
     * @param  list<Product>  $first
     * @param  list<Product>  $second
     */
    private function interleave(array $first, array $second, int $a, int $b, \Closure $add): void
    {
        $i = $j = 0;

        while ($i < count($first) || $j < count($second)) {
            for ($n = 0; $n < $a && $i < count($first); $n++) {
                $add($first[$i++]);
            }

            for ($n = 0; $n < $b && $j < count($second); $n++) {
                $add($second[$j++]);
            }
        }
    }

    /**
     * The warm path: the cached ids, re-checked for visibility and stock.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $c
     * @return Collection<int, Product>
     */
    private function hydrate(array $ids, array $c): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $q = Product::query()
            ->select(ProductController::CARD_COLUMNS)
            ->visible()
            ->whereIn('id', $ids)
            ->with('brand:id,name,slug');

        if ($c['hide_oos']) {
            $q->where(fn ($w) => $w->whereNull('stock_status')->orWhere('stock_status', '!=', 'outofstock'));
        }

        $byId = $q->get()->keyBy(fn ($p) => (int) $p->id);

        $out = collect($ids)->filter(fn ($id) => $byId->has($id))->map(fn ($id) => $byId[$id])->values();

        \App\Support\SetPricing::prime($out);

        return $out;
    }

    /** One SELECT of the union: the card's columns, tagged with its source. */
    private function part(Product $product, array $c, string $src, int $limit): Builder
    {
        $columns = array_map(fn ($col) => 'products.'.$col, ProductController::CARD_COLUMNS);

        $q = Product::query()
            ->select($columns)
            ->selectRaw("'{$src}' as ymal_src")
            ->visible()
            ->where('products.id', '!=', (int) $product->id)
            ->limit(max(1, $limit));

        if ($c['hide_oos']) {
            $q->where(fn ($w) => $w->whereNull('products.stock_status')->orWhere('products.stock_status', '!=', 'outofstock'));
        }

        return $q;
    }

    /**
     * Best sellers first, `id` breaking the tie — the ORDER BY the old rail
     * used, for the reason it gave: without `id` the cards change between two
     * renders of the same page with nothing behind the change.
     */
    private function bestFirst(Builder $q): Builder
    {
        return $q->orderByDesc('products.total_sales')->orderByDesc('products.id');
    }

    /**
     * The product's own categories. Already loaded by the product page's own
     * query (`categories:id,name,slug,path`), so this is free there; anything
     * else that hands over a bare product pays one pluck.
     *
     * @return list<int>
     */
    private function categoryIds(Product $product): array
    {
        $ids = $product->relationLoaded('categories')
            ? $product->categories->pluck('id')
            : $product->categories()->pluck('categories.id');

        return $ids->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    /**
     * Everything that chose these ids. A change to any of it is a different
     * fingerprint, so the cached choice is not used — a changed setting, a
     * changed pick, a moved brand or category never waits for the TTL.
     */
    private function fingerprint(Product $product, array $c, array $picks, array $categoryIds): string
    {
        return md5(json_encode([
            $c['rule'], $c['mix'], $c['fill'], $c['count'], $c['hide_oos'],
            $picks, $product->brand_id, $categoryIds,
        ]) ?: '');
    }
}
