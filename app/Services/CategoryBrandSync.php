<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A category that keeps the same products as a brand.            (2.60.633)
 *
 * The owner, 1 November 2026: "We have Medicube category and also brand,
 * please equal these both, both should have same product like sync."
 *
 * Catalog -> Categories -> a category -> "Same products as brand" names a
 * brand (categories.mirror_brand_id). Then, kept true from both sides:
 *   - every product of that brand is in the category (a category_product
 *     link added with category_position NULL, so a hand-ordered collection
 *     keeps its order on top -- as ConcernSync adds links);
 *   - a product in the category with NO brand at all is given that brand,
 *     so the brand page shows it too.
 * ONLY ADDS. A product of ANOTHER brand that sits in the category is left
 * where it is (it was put there by hand) and only counted, so the screen can
 * say so; nothing is ever unlinked or rebranded away.
 *
 * When: on saving the category, on saving a product whose brand changed (or
 * a new product), and once a day from the scheduler for anything that moved
 * another way (an import, a bulk edit). Each run is two set-based statements
 * per linked category -- an INSERT ... SELECT and an UPDATE -- whatever the
 * catalogue's size; a product save is one indexed lookup and one insert.
 */
final class CategoryBrandSync
{
    public const COLUMN = 'mirror_brand_id';

    /** @return array{linked:int, branded:int, other_brand:int} */
    public static function sync(?int $categoryId = null): array
    {
        $out = ['linked' => 0, 'branded' => 0, 'other_brand' => 0];

        if (! self::ready()) {
            return $out;
        }

        $q = DB::table('categories')->whereNotNull(self::COLUMN);
        if ($categoryId !== null) {
            $q->where('id', $categoryId);
        }

        foreach ($q->get(['id', self::COLUMN]) as $c) {
            $cat = (int) $c->id;
            $brand = (int) $c->{self::COLUMN};

            // Products in the category with no brand take the category's brand.
            $out['branded'] += DB::table('products')->whereNull('brand_id')
                ->whereIn('id', DB::table('category_product')->where('category_id', $cat)->select('product_id'))
                ->update(['brand_id' => $brand, 'updated_at' => now()]);

            // Every product of the brand is in the category.
            $out['linked'] += DB::table('category_product')->insertUsing(['category_id', 'product_id', 'category_position'],
                DB::table('products as p')->where('p.brand_id', $brand)
                    ->whereNotExists(fn ($w) => $w->from('category_product as cp')->whereColumn('cp.product_id', 'p.id')->where('cp.category_id', $cat))
                    ->selectRaw('? as category_id, p.id as product_id, NULL as category_position', [$cat]));

            $out['other_brand'] += DB::table('category_product as cp')->join('products as p', 'p.id', '=', 'cp.product_id')
                ->where('cp.category_id', $cat)->whereNotNull('p.brand_id')->where('p.brand_id', '!=', $brand)->count();
        }

        if ($out['linked'] + $out['branded'] > 0) {
            self::flush();
        }

        return $out;
    }

    /** A product whose brand is mirrored by a category: put it in that category. */
    public static function forProduct(int $productId, ?int $brandId): void
    {
        if ($brandId === null || ! self::ready()) {
            return;
        }

        try {
            $cats = DB::table('categories')->where(self::COLUMN, $brandId)->pluck('id');
            $added = 0;
            foreach ($cats as $cat) {
                $added += DB::table('category_product')->insertOrIgnore(['category_id' => (int) $cat, 'product_id' => $productId, 'category_position' => null]);
            }
            if ($added > 0) {
                self::flush();
            }
        } catch (\Throwable) {
            // The daily run catches up; a product save never fails over this.
        }
    }

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        try {
            return self::$ready ??= Schema::hasColumn('categories', self::COLUMN);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function forgetReady(): void
    {
        self::$ready = null;
    }

    private static function flush(): void
    {
        try {
            \App\Http\Controllers\Store\ShopController::flushSidebarCache();
        } catch (\Throwable) {
        }
        Cache::forget('kbb.home.cats');
        Cache::forget('kbb.home.rails');
    }
}
