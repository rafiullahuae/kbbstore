<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lane CT — the approved contrast colours reach a shop that has SAVED a screen.
 *
 * The owner approved docs/contrast-preview/ on 5 October ("okay proceed"), and
 * this release moves nine colour DEFAULTS to the smallest darkening that reads
 * at 4.5:1. A default only reaches a shop that has no row for the key — and
 * several of these screens store EVERY field on Save, so a shop whose owner
 * once flipped an unrelated toggle on Product styles holds `sale_colour =
 * #E23B57` without ever having touched the sale colour. On that shop the
 * approved change would silently do nothing.
 *
 * So a stored value EXACTLY EQUAL to the old shipped default (case-insensitive,
 * with or without its `#`) is removed and the key falls to the new default. A
 * stored value that differs by a single digit is the owner's own choice and is
 * NOT touched — the console flags it beside the control if it fails 4.5.
 *
 * Listed by hand, never read from the schemas: a migration must do the same
 * thing for ever, whatever a later release does to a default.
 */
return new class extends Migration
{
    /** settings.key => the default it shipped with before this release. */
    private const PLAIN = [
        'brand_accent' => '#e0567b',      // also what SettingsSeeder wrote
        'sale_colour' => '#e23b57',
        'new_colour' => '#1f9d55',
        'cart_bg' => '#e0567b',
        'mhd_search_icon' => '#e0567b',
        'mhd_search_text' => '#e0567b',
        'pdplay_badge_bg' => '#1f9d55',
    ];

    /** Keys inside the `header_settings` JSON row => old default. */
    private const HEADER = [
        'badge_bg' => '#e0567b',
        'logo_accent_col' => '#e0567b',
        'search_style_accent' => '#e0567b',
    ];

    private static function same(mixed $stored, string $old): bool
    {
        if (! is_string($stored)) {
            return false;
        }

        return '#'.strtolower(ltrim(trim($stored, " \t\n\r\0\x0B\""), '#')) === $old;
    }

    public function up(): void
    {
        try {
            foreach (self::PLAIN as $key => $old) {
                $row = DB::table('settings')->where('key', $key)->first(['value']);

                if ($row !== null && self::same($row->value, $old)) {
                    DB::table('settings')->where('key', $key)->delete();
                }
            }

            $row = DB::table('settings')->where('key', 'header_settings')->first(['value']);
            $saved = $row !== null ? json_decode((string) $row->value, true) : null;

            if (is_array($saved)) {
                $changed = false;

                foreach (self::HEADER as $key => $old) {
                    if (array_key_exists($key, $saved) && self::same($saved[$key], $old)) {
                        unset($saved[$key]);
                        $changed = true;
                    }
                }

                if ($changed) {
                    DB::table('settings')->where('key', 'header_settings')->update(['value' => json_encode($saved)]);
                }
            }
        } catch (\Throwable $e) {
            // A fresh install builds the settings table later in the run; there
            // is nothing to move. Named, not swallowed, so a real failure reads.
            if (app()->runningInConsole()) {
                echo 'Contrast colours: could not read the settings table ('.$e->getMessage()."); nothing moved.\n";
            }
        }

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings', 'kbb.home.rails'] as $key) {
            Cache::forget($key);
        }

        try {
            \App\Services\SettingsService::forgetMemo();
            \App\Models\Setting::flushMap();
            \App\Support\Shortcodes::flush();
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        // Nothing to restore: a removed row reads as the default, and the old
        // default is one click away on the control.
    }
};
