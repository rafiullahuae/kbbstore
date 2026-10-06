<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane CH (hierarchy): `categories.short_url`, and every address the shop
 * serves today frozen as it is.
 *
 * The owner imported every category flat and asked for kbeautybliss.com's
 * parent/child tree back, with one condition: "category addresses stay exactly
 * as now". Without this column that is impossible -- `path` is the URL path and
 * it is rebuilt from the parent chain, so putting `toners` under `skincare`
 * would move /collections/toners/ to /collections/skincare/toners/.
 *
 * short_url = 1 means "this category's address is /collections/{its own slug}/
 * whatever its parents are". The tree (parent_id, depth) is free to change
 * under it; `path` -- the address every sitemap, menu, canonical and link
 * builder in the app already reads -- does not.
 *
 * THE BACKFILL FREEZES ONLY WHAT IS SHORT TODAY: a row whose address is its own
 * slug. A row that is already nested keeps its nested address and the old
 * rules, so no URL on any shop moves when this runs. On a fresh database (the
 * test suite, CI) the table is empty and nothing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'short_url')) {
            Schema::table('categories', function (Blueprint $t) {
                $t->boolean('short_url')->default(false);
            });
        }

        DB::table('categories')
            ->where(function ($q) {
                $q->whereColumn('path', 'slug')
                    ->orWhere(fn ($q) => $q->whereNull('path')->whereNull('parent_id'))
                    ->orWhere(fn ($q) => $q->where('path', '')->whereNull('parent_id'));
            })
            ->update(['short_url' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'short_url')) {
            Schema::table('categories', function (Blueprint $t) {
                $t->dropColumn('short_url');
            });
        }
    }
};
