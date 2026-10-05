<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane BR4. The owner, with the live Anua page beside the previews he was sent
 * for 2.60.398: "the brands pages are not loading as per these previews ...
 * please check and do as per preview".
 *
 * Two things kept the Panel header off his brand pages, and the code fixes the
 * first (Store\BrandController::hero() no longer drops a brand with a page
 * banner to Compact). This is the second: a shop that saved Appearance -> Site
 * layout while Compact or Classic was the brand header -- the screen writes
 * every field it shows -- has `layout_brand_hero` STORED, and a stored value
 * beats the shipped Panel default for good. He asked for the Panel, so it is
 * set to Panel. The control stays: Site layout -> Brand page -> Brand header
 * style puts Compact or Classic back.
 *
 * And the phone's name position: stored at the old default, Bottom left, it is
 * moved to Bottom centre, the new default -- "centered align", he asked. Any
 * other stored position was picked on purpose and is left alone, as is every
 * brand's own choice in its "Edit brand header" pop-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->where('key', 'layout_brand_hero')->whereIn('value', ['compact', 'classic'])
            ->update(['value' => 'panel']);
        DB::table('settings')->where('key', 'layout_brand_pill_at')->where('value', 'bottom-left')
            ->update(['value' => 'bottom-center']);

        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach (['kbb.settings', 'kbb.settings.map'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
            }
        }

        \App\Models\Setting::flushMap();

        if (app()->runningInConsole()) {
            echo "Brand pages draw the Panel header, banner or not.\n";
        }
    }

    public function down(): void
    {
        // The earlier header style is not known after the fact; Site layout ->
        // Brand page -> Brand header style puts any of them back.
    }
};
