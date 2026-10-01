<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE COUNTRIES BAR COMES OFF, ON PHONES AND ON DESKTOP.         (Lane PI-B)
 *
 * The owner, after importing his real shop: "Turn off the top countries bar
 * entirely for now."
 *
 * ── TWO HALVES, FOR THE REASON `banner_ships_as_image_slider` NEEDED TWO ────
 *
 * HeaderSettings::SCHEMA now ships `fb_mobile` and `fb_desktop` as `false`,
 * which is the whole change on a shop that has never saved Appearance →
 * Header. But `header_settings` is one settings row holding a JSON object, and
 * on a shop that HAS saved that screen the row carries `true` for both — that
 * migration wrote them itself — and a stored value beats a schema default
 * every time. Without this the default would move and the bar would stay.
 *
 * ── ONLY WHEN THE ROW EXISTS, AND ONLY THE TWO KEYS ─────────────────────────
 *
 * No row means the schema defaults are already the answer, and creating one
 * would change `/admin-api/seo/settings`'s payload for nothing —
 * SeoBackOfficePayloadTest caught exactly that on the banner migration. Every
 * other key in the row (wording, colours, heights, `fb_text_desktop`) is left
 * as it is, so switching either toggle back on returns the strip exactly as
 * he last had it. The flag bar's other ten controls are untouched.
 *
 * "Entirely" is why BOTH keys, and why the existing true->false move is
 * unconditional on the row's current value: a stored `false` stays `false`,
 * a stored `true` becomes `false`, which is the instruction. "For now" is why
 * it is the switches and not the markup.
 *
 * ── AND THE SETTINGS CACHE ──────────────────────────────────────────────────
 *
 * SettingsService keeps the settings map in the cache forever. Core Updates
 * runs cache:clear after an update, but `php artisan migrate --force` from a
 * shell does not, and a row written underneath a warm cache is a row the shop
 * does not read. So the cache is dropped here as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'header_settings')->value('value');
        $moved = 0;

        if (is_string($row)) {
            $header = json_decode($row, true);
            $header = is_array($header) ? $header : [];

            foreach (['fb_mobile', 'fb_desktop'] as $key) {
                if (($header[$key] ?? null) !== false) {
                    $moved++;
                }

                $header[$key] = false;
            }

            DB::table('settings')
                ->where('key', 'header_settings')
                ->update(['value' => json_encode($header), 'updated_at' => now()]);
        }

        app(SettingsService::class)->flush();

        if (app()->runningInConsole()) {
            echo "The countries bar is off on phones and on desktop ({$moved} stored switch(es) moved).\n"
                ."Appearance -> Header -> Flag bar -> \"Show it on phones\" / \"Show it on desktop\" bring it back.\n";
        }
    }

    /**
     * Back on for both, which is what the shop shipped before this.
     *
     * Lossy in the way every default move is: a shop that had switched one
     * of them off by hand before this ran gets it back on, because after
     * up() nothing in the row says which it was.
     */
    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'header_settings')->value('value');

        if (is_string($row)) {
            $header = json_decode($row, true);
            $header = is_array($header) ? $header : [];
            $header['fb_mobile'] = true;
            $header['fb_desktop'] = true;

            DB::table('settings')
                ->where('key', 'header_settings')
                ->update(['value' => json_encode($header), 'updated_at' => now()]);
        }

        app(SettingsService::class)->flush();
    }
};
