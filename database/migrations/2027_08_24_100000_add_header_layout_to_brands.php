<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane BR2: `brands.header_layout`, one brand's own choices for the Panel
 * header -- logo shape, header width, banner heights, content width, picture
 * position, panel background and the phone's pill. JSON, NULL for every brand
 * until the owner changes one in the "Edit brand header" pop-up; NULL follows
 * Appearance → Site layout → Brand page. Read through BrandPanel::sanitize().
 *
 * Its own column rather than a key in `banner`: PageBanner turns a brand's own
 * banner ON by that column being non-NULL, so writing a size there would have
 * switched a banner on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brands') && ! Schema::hasColumn('brands', 'header_layout')) {
            Schema::table('brands', fn (Blueprint $t) => $t->json('header_layout')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('brands') && Schema::hasColumn('brands', 'header_layout')) {
            Schema::table('brands', fn (Blueprint $t) => $t->dropColumn('header_layout'));
        }
    }
};
