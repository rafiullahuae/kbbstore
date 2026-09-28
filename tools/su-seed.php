<?php

/*
 * Preview seed for the scheme-gate shots. Run through `artisan tinker
 * --execute` by tools/su-preview.sh, on top of DemoCatalogueSeeder.
 *
 * Every hostile value here is one the WORDPRESS IMPORT can write and no screen
 * in this admin can: MegaMenuApiController validates highlight_color on save
 * and ReviewController uploads the photo itself. Each sits next to an ordinary
 * value of the same kind, because the picture has to show BOTH halves — the
 * attack neutralised AND the honest row still drawn exactly as it was.
 */

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Review;

$menu = Menu::query()->where('show_desktop', true)->first()
    ?? Menu::query()->orderBy('id')->first();

abort_unless((bool) $menu, 500, 'no menu to seed into');

$menu->forceFill(['show_mobile' => true, 'show_desktop' => true])->save();
MenuItem::where('menu_id', $menu->id)->delete();

/*
 * highlight_color is varchar(9). `#fff;x:y` is nine characters and is a real
 * break-out: it closes `background:` and opens a declaration of its own, and
 * the template writes four more after it.
 */
$rows = [
    ['label' => 'Home',       'url' => '/',                   'badge' => null,  'highlight_color' => null],
    ['label' => 'Shop',       'url' => '/shop/',              'badge' => null,  'highlight_color' => null],
    ['label' => 'New In',     'url' => '/shop/?orderby=date', 'badge' => 'NEW', 'highlight_color' => null],
    ['label' => 'Super Sale', 'url' => '/super-sale/',        'badge' => null,  'highlight_color' => '#E23A4E'],
    ['label' => 'JS Row',     'url' => 'javascript:alert(1)', 'badge' => 'XSS', 'highlight_color' => null],
    ['label' => 'Rel Row',    'url' => '//evil.test/x',       'badge' => null,  'highlight_color' => null],
    ['label' => 'CSS Row',    'url' => '/toners/',            'badge' => null,  'highlight_color' => '#fff;x:y'],
    ['label' => 'Email Us',   'url' => 'mailto:hi@shop.ae',   'badge' => null,  'highlight_color' => null],
];

$parent = null;

foreach (array_values($rows) as $i => $row) {
    $item = MenuItem::create($row + ['menu_id' => $menu->id, 'position' => $i, 'target_type' => 'custom']);

    if ($row['label'] === 'Shop') {
        $parent = $item;
    }
}

foreach ([
    ['label' => 'All products', 'url' => '/shop/'],
    ['label' => 'Sub JS Row',   'url' => 'javascript:alert(2)'],
] as $i => $child) {
    MenuItem::create($child + [
        'menu_id' => $menu->id,
        'parent_id' => $parent->id,
        'target_type' => 'custom',
        'position' => $i,
    ]);
}

$product = Product::updateOrCreate(['slug' => 'scheme-gate-serum'], [
    'name' => 'Scheme Gate Serum',
    'status' => 'publish',
    'is_visible' => true,
    'price' => 9900,
    'stock_status' => 'instock',
    'type' => 'simple',
    'image' => '/uploads/js/photo.png',
    'short_description' => 'The product the review photo strip is photographed on.',
]);

Review::where('product_id', $product->id)->delete();

Review::create([
    'product_id' => $product->id,
    'author_name' => 'Ordinary Shopper',
    'rating' => 5,
    'title' => 'Lovely',
    'content' => 'An ordinary review with an ordinary photo address.',
    'images' => ['/uploads/js/photo.png'],
    'status' => 'approved',
    'verified' => true,
]);

Review::create([
    'product_id' => $product->id,
    'author_name' => 'Imported Row',
    'rating' => 4,
    'title' => 'From a database this shop did not author',
    'content' => 'Four photo addresses; two of them are not addresses a picture can have.',
    'images' => [
        '/uploads/js/photo.png',
        'javascript:alert(3)',
        '//127.0.0.1:8993/uploads/js/injected.png',
        '/uploads/js/photo.png',
    ],
    'status' => 'approved',
    'verified' => false,
]);

/*
 * The footer's social row. Instagram is refused and must lose its icon
 * entirely rather than gain an href of "#"; TikTok is ordinary and must render
 * byte for byte.
 */
app(\App\Services\SettingsService::class)->set('social_instagram', 'javascript:alert(4)');
app(\App\Services\SettingsService::class)->set('social_tiktok', 'https://www.tiktok.com/@kbeauty.bliss');
app(\App\Services\SettingsService::class)->set('social_facebook', 'https://www.facebook.com/kbeautyblissuae');

\Illuminate\Support\Facades\Cache::flush();

echo "su seed: menu {$menu->id} product {$product->slug}\n";
