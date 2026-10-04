<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The owner's phone-carousel request reaches a shop that has SAVED its
 * settings.                                                       (Lane PF)
 *
 * The owner, 4 October: "show 2.2, 2.3 2.5 etc products … have option to hide
 * un-hide the arrows in mobile, keep off in mobile". The new defaults are 2.3
 * cards in view and the phone arrows off, for Big savings bundles
 * (`home_hb_per_m`, `home_hb_arrows_m`) and the homepage Spotted carousel
 * (`spotted_per_m`, `spotted_arrows_m`). A default only reaches a shop that
 * never saved the screen; Appearance → Homepage content and Appearance →
 * #KBeautyBliss Spotted both write every field on Save, so the live shop holds
 * the OLD values as rows. What he asked for ships on (CLAUDE.md, 30
 * September): where a row exists it is moved to his answer. Where none exists
 * the new default already applies and nothing is written.
 *
 * Every switch is still there to move back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = app(SettingsService::class);
        $moved = [];

        foreach (['home_hb_per_m' => '2.3', 'home_hb_arrows_m' => false, 'spotted_per_m' => '2.3', 'spotted_arrows_m' => false] as $key => $value) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                $settings->set($key, $value);
                $moved[] = $key;
            }
        }

        if ($moved !== []) {
            $settings->flush();
        }

        if (app()->runningInConsole()) {
            echo 'Phone carousels: 2.3 cards in view and the arrows off ('.count($moved)." saved setting(s) moved).\n"
                ."  Appearance -> Homepage content -> Big savings bundles -> Cards in view / Arrows · phone\n"
                ."  Appearance -> #KBeautyBliss Spotted -> Cards in view / Arrows · phone\n";
        }
    }

    public function down(): void {}
};
