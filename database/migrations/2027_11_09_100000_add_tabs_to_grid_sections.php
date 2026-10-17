<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Lane HTB: "Product tabs" — a Grid section shown as tabs. The owner: "i need
 * Tabs section on homepage like different tabs in one section, like Best
 * Sellers, Trending K-Beauty, New Arrivals etc." ... "i will need these tabs
 * later on as well, so i will be able to create the tab sections myself, and
 * add it on homepage like other sections".
 *
 * Two NULLABLE columns and nothing else. NULL is "an ordinary grid", which is
 * what every existing row is, so no row changes and no shop page moves when
 * this runs. No row is created: the "Product tabs" preset on Appearance →
 * Grid sections creates one, as a draft, when the owner presses it.
 *
 *   tabs       JSON — the tabs and their section-level words (App\Support\GridTabs).
 *   tab_style  'a' (underlined words) or 'b' (pill buttons).
 *
 * Literal-only (no App\ classes), guarded column by column so a package applied
 * twice does nothing. The two grid-section cache keys are forgotten by name so
 * the next homepage reads the new columns rather than a cached model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('grid_sections')) {
            return;
        }

        if (! Schema::hasColumn('grid_sections', 'tabs')) {
            Schema::table('grid_sections', fn (Blueprint $t) => $t->json('tabs')->nullable());
        }

        if (! Schema::hasColumn('grid_sections', 'tab_style')) {
            Schema::table('grid_sections', fn (Blueprint $t) => $t->string('tab_style', 8)->nullable());
        }

        Cache::forget('kbb.home.gridsections.registry');
        Cache::forget('kbb.home.gridsections.home');
    }

    public function down(): void
    {
        foreach (['tabs', 'tab_style'] as $column) {
            if (Schema::hasTable('grid_sections') && Schema::hasColumn('grid_sections', $column)) {
                Schema::table('grid_sections', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
