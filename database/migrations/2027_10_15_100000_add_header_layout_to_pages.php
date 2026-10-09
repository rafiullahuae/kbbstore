<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane PH: `pages.header_layout`, one content page's own choices for its
 * header in the brand page's design (App\Support\BrandPanel::forPage()):
 *
 *   hero   'brand' or 'banner' -- the brand-page design, or the normal page
 *          banner it drew before; absent follows Appearance -> Site layout ->
 *          Page header (brand design)
 *   image  its own Header picture (an uploaded path or an http(s) address)
 *   title  its own title in the header, English
 *   sub    its own subtitle under it, English
 *
 * NULL for every page until the owner changes one in Pages -> User pages ->
 * Edit page -> Page header. Its own column, as categories.header_layout is:
 * `seo` is the search engines' and `doc_json` the old builder's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pages') && ! Schema::hasColumn('pages', 'header_layout')) {
            Schema::table('pages', fn (Blueprint $t) => $t->json('header_layout')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pages') && Schema::hasColumn('pages', 'header_layout')) {
            Schema::table('pages', fn (Blueprint $t) => $t->dropColumn('header_layout'));
        }
    }
};
