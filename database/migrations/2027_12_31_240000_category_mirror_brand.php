<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.633: a category that keeps the same products as a brand
 * (App\Services\CategoryBrandSync). The owner, 1 November 2026: "We have
 * Medicube category and also brand, please equal these both, both should
 * have same product like sync."
 *
 * Adds categories.mirror_brand_id and, as he asked, links the Medicube
 * category to the Medicube brand and brings them level now: every Medicube
 * product into the category, and a product in the category with no brand
 * given Medicube. ONLY ADDS -- nothing is unlinked or rebranded away; a
 * product of another brand in the category stays. Found by slug; a shop
 * without either row is left alone. Catalog -> Categories -> Medicube ->
 * "Same products as brand" undoes the link. LITERALS ONLY (the 2.60.512
 * rule): the same two statements CategoryBrandSync runs, written out.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        if (! Schema::hasColumn('categories', 'mirror_brand_id')) {
            Schema::table('categories', function (Blueprint $t) {
                $t->unsignedBigInteger('mirror_brand_id')->nullable()->index();
            });
        }

        if (! Schema::hasTable('brands') || ! Schema::hasTable('category_product')) {
            return;
        }

        $cat = DB::table('categories')->where('slug', 'medicube')->value('id');
        $brand = DB::table('brands')->where('slug', 'medicube')->value('id');

        if ($cat === null || $brand === null) {
            return;
        }

        DB::table('categories')->where('id', $cat)->whereNull('mirror_brand_id')->update(['mirror_brand_id' => $brand]);

        $branded = DB::table('products')->whereNull('brand_id')
            ->whereIn('id', DB::table('category_product')->where('category_id', $cat)->select('product_id'))
            ->update(['brand_id' => $brand]);

        $cols = Schema::hasColumn('category_product', 'category_position') ? ['category_id', 'product_id', 'category_position'] : ['category_id', 'product_id'];
        $linked = DB::table('category_product')->insertUsing($cols,
            DB::table('products as p')->where('p.brand_id', $brand)
                ->whereNotExists(fn ($w) => $w->from('category_product as cp')->whereColumn('cp.product_id', 'p.id')->where('cp.category_id', $cat))
                ->selectRaw(count($cols) === 3 ? '? as category_id, p.id as product_id, NULL as category_position' : '? as category_id, p.id as product_id', [$cat]));

        // The shop's category lists and counts (Store\ShopController::flushSidebarCache's keys).
        foreach (['kbb.home.cats', 'kbb.home.rails', 'kbb.shop.cats', 'kbb.shop.brands'] as $key) {
            Cache::forget($key);
        }
        try {
            if (class_exists(\App\Support\CategoryRollup::class)) {
                \App\Support\CategoryRollup::forgetLists();
            }
        } catch (\Throwable) {
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Medicube category = Medicube brand: {$linked} product(s) added to the category, {$branded} given the brand.\n";
        }
    }

    public function down(): void
    {
        // Links and brands are content: kept. Only the setting goes.
        if (Schema::hasColumn('categories', 'mirror_brand_id')) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('mirror_brand_id'));
        }
    }
};
