<?php

declare(strict_types=1);

/*
 * Lane MG: a shop whose desktop menu is shaped like the owner's screenshot —
 * a mid-row "All Brands" of ninety brands (nine columns), a three-column
 * "Skincare" near the right end and a single-column "Hair Care" — with the
 * Arabic storefront on, so the panels can be photographed in both directions.
 * Run through tools/mg-preview.sh; never against a real database.
 */

use App\Models\Menu;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../tests/Support/MegaMenuShape.php';

DB::table('menu_items')->delete();
DB::table('menus')->delete();

$menu = Menu::create(['name' => 'MG menu', 'slug' => 'mg-menu', 'show_desktop' => true, 'show_mobile' => true, 'show_footer' => false]);

$put = function (array $items, ?int $parent) use (&$put, $menu): void {
    foreach (array_values($items) as $i => $item) {
        $id = DB::table('menu_items')->insertGetId([
            'menu_id' => $menu->id, 'parent_id' => $parent, 'label' => $item['label'], 'url' => '/shop/',
            'badge' => $item['badge'] ?? null, 'highlight_color' => $item['highlight_color'] ?? null,
            'columns' => $item['columns'] ?? null, 'position' => $i, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $put($item['children'] ?? [], $id);
    }
};
$put(Tests\Support\MegaMenuShape::items(), null);

// An owner to sign in as, for the picture of Appearance → Header → Navigation.
\App\Models\AdminUser::query()->where('email', 'mg@preview.test')->exists() || \App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'mg@preview.test',
    'password' => 'mg-preview-secret-1', 'role' => 'owner',
]);

app(\App\Services\SettingsService::class)->set('language_ar_enabled', '1');
app(\App\Services\SettingsService::class)->set('language_rtl_enabled', '1');

if (getenv('KBB_MG_OFF') === '1') {
    app(\App\Services\HeaderSettings::class)->save(['mega_fit' => false]);
}

Cache::flush();
echo 'MG menu: '.DB::table('menu_items')->count()." rows\n";
