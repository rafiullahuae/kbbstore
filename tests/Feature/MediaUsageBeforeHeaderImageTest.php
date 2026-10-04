<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Support\MediaUsage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The media index on a database that has not reached 2027_07_12 yet.
 *                                                                (2.60.374)
 *
 * THE DEFECT: MediaUsage::index() selected `brands.header_image` and
 * `categories.header_image` unconditionally. Migrations dated BEFORE the one
 * that adds that column (2026_10_10_000001_backfill_media_usages and the media
 * backfills after it) run this code, so building a database from nothing died
 * with "Unknown column 'header_image' in brands" -- every MySQL test run failed
 * in setup, and so would any fresh install of the shop. The live database was
 * not affected: it ran those migrations long before the column was read.
 *
 * WHY THE SQL IS READ RATHER THAN THE ERROR WAITED FOR: SQLite answers an
 * unknown double-quoted column with the column's NAME AS A STRING, so on the
 * default (SQLite) suite the broken query succeeds -- which is exactly how this
 * reached the MySQL lane unseen. So the test asserts no query names the column.
 *
 * MUTATION (RUN): put 'header_image' back into the brands select() list and
 * this is red.
 */
it('builds the media index on tables that do not have header_image yet', function () {
    Brand::create(['name' => 'Mhi Test Brand', 'slug' => 'mhi-test-brand', 'logo' => '/uploads/mhi-brand.jpg']);
    Category::create(['name' => 'Mhi Test Cat', 'slug' => 'mhi-test-cat', 'image' => '/uploads/mhi-cat.jpg']);

    foreach (['brands', 'categories'] as $table) {
        Schema::table($table, fn (Blueprint $t) => $t->dropColumn('header_image'));
    }

    $sql = [];
    DB::listen(function ($q) use (&$sql) { $sql[] = $q->sql; });

    $index = MediaUsage::index();

    expect(array_values(array_filter($sql, fn ($q) => str_contains($q, 'header_image'))))->toBe([]);

    expect($index)->toBeArray()
        ->and(json_encode($index))->toContain('mhi-brand.jpg')
        ->and(json_encode($index))->toContain('mhi-cat.jpg');
});
