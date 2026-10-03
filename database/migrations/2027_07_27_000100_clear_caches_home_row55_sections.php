<?php

declare(strict_types=1);

use App\Services\HomepageSections;
use App\Services\SettingsService;
use App\Support\HomeSections;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Master plan row 55 — the owner's homepage, applied to a shop that has
 * already SAVED its homepage.                                       (Lane HA)
 *
 * HomepageSections::OFF_BY_DEFAULT and the new registry order are DEFAULTS,
 * and a default reaches only a shop that never saved Appearance → Homepage.
 * The live shop has saved it, so without this the package would add the new
 * sections and leave the old page standing around them — the opposite of
 * "don't include anything from our existing homepage ... except banner".
 * CLAUDE.md, 30 September: what he asked for ships ON.
 *
 * WHAT IT WRITES, AND NOTHING ELSE:
 *
 *   homepage_sections   every OFF_BY_DEFAULT row switched off on both
 *                       devices (the row, its skin, background and width are
 *                       kept, so switching one back on restores it exactly),
 *                       and every row's saved `order` dropped, so the page
 *                       runs in the order he gave rather than an order saved
 *                       before these sections existed. Each row stays on
 *                       Appearance → Homepage with its ↑/↓ and its switches.
 *   about_text          his four paragraphs, verbatim — ONLY when the shop
 *                       had saved a value; an unsaved shop reads them as the
 *                       new default already.
 *
 * Nothing at all on a shop that has saved neither, which is every test
 * database: the defaults already say the same thing.
 *
 * Then the compiled views and the cached settings and homepage entries, so
 * the page that follows reads the new answer rather than a ten-minute-old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = app(SettingsService::class);

        $row = DB::table('settings')->where('key', 'homepage_sections')->first();
        $saved = $row === null ? null : $settings->get('homepage_sections');

        if (is_array($saved)) {
            foreach ($saved as $key => $section) {
                if (! is_array($section)) {
                    continue;
                }

                unset($section['order']);

                if (in_array($key, HomepageSections::OFF_BY_DEFAULT, true)) {
                    $section['desktop'] = false;
                    $section['mobile'] = false;
                }

                $saved[$key] = $section;
            }

            $settings->set('homepage_sections', $saved);
        }

        if (DB::table('settings')->where('key', 'about_text')->exists()) {
            $settings->set('about_text', HomeSections::ABOUT_DEFAULT);
        }

        $settings->flush();

        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings',
            'kbb.home.rails', 'kbb.home.brands', 'kbb.home.posts'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
            }
        }

        HomeSections::flush();
    }

    public function down(): void {}
};
