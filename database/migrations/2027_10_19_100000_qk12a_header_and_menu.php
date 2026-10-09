<?php

declare(strict_types=1);

use App\Services\HeaderSettings;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * Lane QK12, chunk A (header and menu). The owner, 9 October, on phone
 * screenshots:
 *
 *   "remove the tick arrow that comes with cart panel when adding product to
 *    cart, AND in mobile menu, remove the red color of super sale menu, add a
 *    flash icon with super sale"
 *   "the mobile menu icon i need simple three lines but beautiful. also give
 *    option on backend to change back anytime."
 *
 * Written as STORED values over whatever was saved, because each is a newer
 * instruction than anything saved on those screens before it; a moved default
 * alone would not reach a shop that has saved the screen:
 *
 *   - Appearance -> Cart panel -> Behaviour -> "When something is added": None.
 *   - Appearance -> Mobile menu -> Style -> "Super Sale highlight": off.
 *     ("Flash icon beside Super Sale" is new, so its default -- on -- applies.)
 *   - Appearance -> Header -> Menu icon -> Icon: Three lines.
 *
 * Each is one press away on its screen. Nothing else in any of the three stored
 * maps is touched: the mobile menu's array is read, one key changed and written
 * back (MobileMenu::save() replaces the whole array, quick links included, so
 * it is not the tool for a single key), and HeaderSettings::save() merges.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $settings = app(SettingsService::class);
        SettingsService::forgetMemo();

        $settings->set('cartpanel_add_feedback', 'none');

        $menu = $settings->get('mobile_menu');
        $menu = is_array($menu) ? $menu : [];
        $menu['sale_fill'] = false;
        $settings->set('mobile_menu', $menu);

        SettingsService::forgetMemo();
        app(HeaderSettings::class)->save(['menu_icon' => 'lines']);
        SettingsService::forgetMemo();
    }

    public function down(): void
    {
        //
    }
};
