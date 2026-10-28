<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.620 -- GIFT BOX POPUPS, the owner's feedback on 2.60.619: "turn off the
 * click on the products images ... i want little spacing above the gift box
 * in the cart panel. plz allow this control".
 *
 * DEFAULTS MOVED, because he asked: the photo popup has a new "off" option and
 * ships at it (FreeGifts::EXTRAS['pop']), and the cart panel's space above the
 * box ships at 12px (EXTRAS['panel_gap']). A shop that already saved the
 * popups' settings has pop "A" written in its row -- the 2.60.619 default,
 * saved the first time any free gifts control was used -- so the code default
 * alone would leave his photos clickable. This writes "off" into that one key
 * and nothing else; the picker (Marketing -> Free gifts -> Overview -> Gift
 * box popups -> Photo popup) brings A-D back. No row, or no popups settings in
 * it: nothing to change, the code default applies.
 *
 * Then views, config, routes and the event cache are cleared and opcache
 * reset, so the new box views and PHP are what runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            $raw = DB::table('settings')->where('key', 'free_gifts')->value('value');
            $saved = is_string($raw) ? json_decode($raw, true) : null;

            if (is_array($saved) && is_array($saved['extras'] ?? null)) {
                $saved['extras']['pop'] = 'off';
                DB::table('settings')->where('key', 'free_gifts')->update(['value' => json_encode($saved), 'updated_at' => now()]);
            }

            try {
                foreach (['kbb.settings', 'kbb.settings.map'] as $key) {
                    Cache::forget($key);
                }
            } catch (\Throwable) {
                // A cache that cannot forget expires on its own.
            }
        }

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/events.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    public function down(): void {}
};
