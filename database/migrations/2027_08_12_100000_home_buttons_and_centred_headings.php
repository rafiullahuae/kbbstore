<?php

declare(strict_types=1);

use App\Services\SettingsService;
use App\Support\HomeSections;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 2.60.385 — homepage section buttons and centred headings.
 *
 * The owner asked for a button on Under AED 54 ("on this section too on
 * homepage"). Its new default reaches only a shop that never saved the Under
 * AED 54 tab; a shop that did saved the old default, which was EMPTY, and an
 * empty text means "no button". So an empty saved text or link becomes the new
 * default here — the value he asked for. A shop that typed its own keeps it.
 *
 * Then the compiled views and the cached settings, so the next page reads the
 * new homepage rather than a ten-minute-old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = app(SettingsService::class);

        foreach (['home_u54_btn' => HomeSections::U54_BTN, 'home_u54_url' => HomeSections::U54_URL] as $key => $value) {
            if (DB::table('settings')->where('key', $key)->exists() && trim((string) $settings->get($key)) === '') {
                $settings->set($key, $value);
            }
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

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings', 'kbb.home.rails'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
            }
        }

        HomeSections::flush();

        if (app()->runningInConsole()) {
            echo "Homepage: Under AED 54 and Spotted buttons, no dot beside a centred heading.\n";
        }
    }

    public function down(): void
    {
        // Nothing to undo: the texts are ordinary settings on Appearance →
        // Homepage content → Under AED 54, and the caches rebuild themselves.
    }
};
