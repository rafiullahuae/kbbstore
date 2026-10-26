<?php

declare(strict_types=1);

use App\Services\NewArrivals;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * Lane NA -- New Arrivals keeps itself: the newest products added in the last
 * 3 months, at most 300, newest on top (App\Services\NewArrivals).
 *
 * The owner asked for it ON, so it ships on: the category whose slug is
 * new-arrivals gets the default rule, and its rows are filled now.
 *
 * SAFE ON PRODUCTION, AND SAFE TWICE:
 *   - one nullable column and one new table, each created only if absent;
 *   - the rule is written only where none is stored yet, so a second run (or
 *     a rule the owner has since changed) is left alone;
 *   - before the first fill the category's hand-made rows are copied aside
 *     (category_auto_picks, kind 'manual'), so switching it off in Catalog ->
 *     New Arrivals puts them back exactly;
 *   - only rows of THIS category are written. No other category, and no
 *     product's primary category, is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', NewArrivals::COLUMN)) {
            Schema::table('categories', function (Blueprint $t) {
                $t->text(NewArrivals::COLUMN)->nullable();
            });
        }

        if (! Schema::hasTable(NewArrivals::TABLE)) {
            Schema::create(NewArrivals::TABLE, function (Blueprint $t) {
                $t->foreignId('category_id')->constrained()->cascadeOnDelete();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->string('kind', 8);            // include | exclude | manual
                $t->integer('position')->nullable(); // 'manual' only: the hand-made order kept aside
                $t->timestamp('created_at')->nullable();
                $t->primary(['category_id', 'product_id', 'kind']);
            });
        }

        $category = DB::table('categories')->where('slug', NewArrivals::SLUG)->first();

        if ($category !== null && ($category->{NewArrivals::COLUMN} ?? null) === null) {
            try {
                NewArrivals::forget();
                $model = \App\Models\Category::query()->find($category->id);
                NewArrivals::saveRule($model, NewArrivals::DEFAULT_RULE);
            } catch (\Throwable $e) {
                // The minute sync fills it; a migration must not fail the update for this.
                Log::error('New Arrivals first fill failed: '.$e->getMessage());
            }
        }

        NewArrivals::forget();
    }

    public function down(): void
    {
        Schema::dropIfExists(NewArrivals::TABLE);

        if (Schema::hasColumn('categories', NewArrivals::COLUMN)) {
            Schema::table('categories', function (Blueprint $t) {
                $t->dropColumn(NewArrivals::COLUMN);
            });
        }
    }
};
