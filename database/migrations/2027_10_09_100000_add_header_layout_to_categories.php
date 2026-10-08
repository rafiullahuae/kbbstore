<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane CB: `categories.header_layout`, one category's own choices for the
 * category banner -- the brand page's Panel header on a category page. The
 * same JSON `brands.header_layout` holds (BrandPanel::sanitize() reads both):
 * the panel's background, the phone's pill, the logo shape, the header and
 * banner sizes, the panel's place, the type sizes and the outer spacing.
 * NULL for every category until the owner changes one in Catalog ->
 * Categories -> Edit -> Category header -> Banner layout; NULL follows
 * Appearance -> Site layout -> Category banner.
 *
 * Its own column, for the brand's reason: PageBanner turns a page banner ON
 * by `banner` being non-NULL, and `header_style` belongs to the title header.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('categories') && ! Schema::hasColumn('categories', 'header_layout')) {
            Schema::table('categories', fn (Blueprint $t) => $t->json('header_layout')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'header_layout')) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('header_layout'));
        }
    }
};
