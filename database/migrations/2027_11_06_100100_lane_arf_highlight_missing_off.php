<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane ARF: no ⟪brackets⟫ for anyone.
 *
 * `translation_highlight_missing` (Translation -> Language settings -> "Show
 * what is not translated yet") is the review switch that draws every
 * interface string without published Arabic as ⟪English⟫. It was on when the
 * owner browsed /ar: "why every heading etc is inside the arrow < > bracket?
 * ... translate everything without such weird arrow brackets." With the
 * Arabic published by 2027_11_06_100000 there is nothing left for it to show,
 * and the shop is live, so it is turned OFF -- stored '0', which
 * SettingsService decodes to a falsy value exactly as the admin switch's own
 * save does. He can turn it back on from the same screen.
 *
 * The Arabic on/off switch (language_ar_enabled) is NOT touched, in either
 * direction: whether /ar is served is his call. Literals and framework calls
 * only; ArabicByDefaultTest pins it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        // A shop that never saved the switch already has it off (the default),
        // and gains no row: the settings payload stays exactly what it was.
        DB::table('settings')
            ->where('key', 'translation_highlight_missing')
            ->update(['value' => '0', 'updated_at' => now()]);

        $this->forgetCaches();
    }

    /** Nothing to restore: off is the shipped default, and on was a review tool. */
    public function down(): void {}

    /** The settings maps and the translation maps (the loader decorates with the switch), by their literal keys. */
    private function forgetCaches(): void
    {
        try {
            foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings', 'kbb.translations.ar', 'kbb.translations.en'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }
    }
};
