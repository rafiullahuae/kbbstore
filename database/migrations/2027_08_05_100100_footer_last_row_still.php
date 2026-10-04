<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The site footer's last row stops moving. (Lane FT)
 *
 * The owner, 4 October: "also remove the effect from the very last row of the
 * footer. animation not from the logo." SiteFooter::SCHEMA['site_sheen'] now
 * ships 'off', which covers a shop that never saved the Footer screen. The old
 * screen saved EVERY value it held, though, so a shop that pressed Save there
 * has 'bar' stored and would keep the shine. This turns that one stored value
 * off — and only that one: 'name' (a shine he chose for the big name) and a
 * missing row are left exactly as they are. The big name's slow colour drift
 * is a different setting (`site_motion`) and is not touched.
 *
 * Switch it back at Appearance → Footer → Site footer · Desktop (or · Mobile)
 * → Bottom bar → "Effect on the last row (the shine)".
 */
return new class extends Migration
{
    public function up(): void
    {
        $key = \App\Services\SiteFooter::PREFIX.'site_sheen';
        $row = DB::table('settings')->where('key', $key)->value('value');

        if ($row === 'bar') {
            app(SettingsService::class)->set($key, 'off');
        }

        if (app()->runningInConsole()) {
            echo "The site footer's last row no longer shines (Appearance → Footer → Bottom bar → Effect on the last row).\n";
        }
    }

    public function down(): void {}
};
