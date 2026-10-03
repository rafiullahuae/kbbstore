<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lane RG: the space under the product page's detail tab row, per device.
 *
 *   "give controls of details tab heading and the content between spacing as
 *    marked, also seperate for mobile."
 *   "need spacing beteen the tab heading and content in mobile, and desktop
 *    both."
 *
 * `pdplay_tab_body_gap` was one value for both widths. It is now the PHONE's
 * (Appearance → Product page → Spacing · Page → "Space under the detail tab
 * row · phone") and `pdplay_tab_body_gap_d` is the laptop's.
 *
 * ▲ A DEFAULT MOVES, BECAUSE HE ASKED FOR SPACE ON BOTH. The shipped number is
 *   18px. His phone shows the text almost touching the tab row and his laptop
 *   about 10px under it, which is what a saved value well under 18 draws. So a
 *   saved value BELOW 18 is lifted to 18 on both devices; a saved value of 18
 *   or more is his choice of MORE room and is kept on both (copied into the
 *   laptop key). No saved value: nothing is written, both read 18.
 *
 * Idempotent: a second run finds the laptop key and the lifted phone value
 * already there and changes nothing.
 */
return new class extends Migration
{
    private const PHONE = 'pdplay_tab_body_gap';

    private const LAPTOP = 'pdplay_tab_body_gap_d';

    private const ASKED = 18;

    public function up(): void
    {
        $row = DB::table('settings')->where('key', self::PHONE)->first();

        if ($row === null) {
            return;
        }

        $saved = is_numeric($row->value) ? (int) $row->value : self::ASKED;
        $value = max(self::ASKED, min(48, $saved));
        $settings = app(SettingsService::class);

        if ($value !== $saved || (string) $row->value !== (string) $value) {
            $settings->set(self::PHONE, $value);
        }

        if (! DB::table('settings')->where('key', self::LAPTOP)->exists()) {
            $settings->set(self::LAPTOP, $value);
        }

        $settings->flush();

        if (app()->runningInConsole()) {
            echo "Product page: detail tab row gap {$saved}px → phone {$value}px, laptop {$value}px.\n";
        }
    }

    public function down(): void {}
};
