<?php

/*
 * Lane JS preview seed. Run through `artisan tinker --execute` by
 * tools/js-preview.sh, on top of DemoCatalogueSeeder.
 *
 * Three hostile values, one per hole the sweep found, each alongside an
 * ordinary one so the shots prove the page still draws:
 *
 *  1. a MENU ROW whose url is `javascript:…`, which mobile-nav.js escaped and
 *     never scheme-checked, so it arrived in the drawer's href intact;
 *  2. a REVIEW PHOTO whose address is `javascript:…` and a second one that is
 *     protocol-relative, which reviews.js escaped and never scheme-checked;
 *  3. a COUPON whose refusal message carries markup, which cart.js interpolated
 *     into innerHTML raw. The message is written straight into the response by
 *     the preview route below rather than by CouponService, because the point
 *     of the picture is what the BROWSER does with an error string, not which
 *     PHP file composed it.
 *
 * `/uploads/js/injected.png` is the file that must never be requested: it is
 * what the hostile review photo tries to load, and the shot harness counts
 * every request for it.
 */

use App\Models\Menu;
use App\Models\ModuleToggle;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Review;

/* ---------------------------------------------------------------- nav --- */

/*
 * The menu the storefront actually serves, not a new one. NavigationService
 * picks the menu whose `show_mobile` column is set, so creating a second menu
 * here would seed rows nothing renders — and `menus.slug` is NOT NULL, which is
 * how the first cut of this seed failed. Reuse whatever DemoCatalogueSeeder
 * left, make sure it holds both slots, and replace its rows.
 */
$menu = Menu::query()->where('show_mobile', true)->first()
    ?? Menu::query()->orderBy('id')->first();

abort_unless((bool) $menu, 500, 'no menu to seed into');

$menu->forceFill(['show_mobile' => true, 'show_desktop' => true])->save();

MenuItem::where('menu_id', $menu->id)->delete();

$rows = [
    ['label' => 'Home',      'url' => '/',                    'badge' => null,  'position' => 0],
    ['label' => 'Shop',      'url' => '/shop/',               'badge' => null,  'position' => 1],
    ['label' => 'New In',    'url' => '/shop/?orderby=date',  'badge' => 'NEW', 'position' => 2],
    // The hostile one. Read it out loud: nothing in it is a character an HTML
    // escaper touches, so escaping alone let it through whole.
    ['label' => 'JS Row',    'url' => 'javascript:alert(1)',  'badge' => 'XSS', 'position' => 3],
    // And the protocol-relative one: somebody else's host wearing this page's
    // scheme, which is not refused by "does it start with a scheme" either.
    ['label' => 'Rel Row',   'url' => '//evil.test/x',        'badge' => null,  'position' => 4],
    // Allowed, and the reason the drawer uses the operator allowlist: the
    // desktop nav renders this same row through Url::to(), which passes
    // mailto through by name.
    ['label' => 'Email Us',  'url' => 'mailto:hi@shop.ae',    'badge' => null,  'position' => 5],
];

$parent = null;

foreach ($rows as $row) {
    $item = MenuItem::create($row + ['menu_id' => $menu->id, 'target_type' => 'custom']);

    if ($row['label'] === 'Shop') {
        $parent = $item;
    }
}

// A second level, so the sub-panel's three href sites are photographed too.
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

/* ------------------------------------------------------------ reviews --- */

$product = Product::updateOrCreate(['slug' => 'js-sweep-serum'], [
    'name' => 'JS Sweep Serum',
    'status' => 'publish',
    'is_visible' => true,
    'price' => 9900,
    'stock_status' => 'instock',
    'type' => 'simple',
    'image' => '/uploads/js/photo.png',
    'short_description' => 'The product the review modal is photographed on.',
]);

Review::where('product_id', $product->id)->delete();

// The honest review: an ordinary photo address, so the shots prove a real
// review photo still draws.
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

// The hostile one. Three addresses: one that works, one javascript: and one
// protocol-relative pointing at the file that must never be fetched.
Review::create([
    'product_id' => $product->id,
    'author_name' => 'Imported Row',
    'rating' => 4,
    'title' => 'From a database this shop did not author',
    'content' => 'Its photo addresses carry a scheme.',
    'images' => [
        '/uploads/js/photo.png',
        'javascript:alert(3)',
        '//127.0.0.1:8991/uploads/js/injected.png',
    ],
    'status' => 'approved',
    'verified' => false,
]);

/* ------------------------------------------------------- cart coupon --- */

/*
 * PREVIEW ONLY, and it changes nothing in the repository. The coupon field is
 * behind a module toggle that ships OFF, and a refused coupon is the one path
 * that fills `#kbbCartNotices` — the element cart.js was interpolating
 * `data.error` into. Without it there is no picture of the fix at all.
 */
ModuleToggle::updateOrCreate(['module' => 'cart_coupon_field'], ['enabled' => true]);

/* --------------------------------------------------- currency symbol --- */

/*
 * PREVIEW ONLY. `currency_symbol` is stored by the admin as free text, and
 * Money::plain() puts it into `data-price` on every variant row. Blade escapes
 * that for the attribute and the HTML parser decodes it again, so pdp.js read
 * the live characters back and wrote them into innerHTML when a bundle was
 * picked.
 *
 * The contrast is the point of the picture: the SERVER-rendered prices on the
 * same page go through Money::format(), which escapes the symbol itself, so
 * they are unaffected either way. Only the JavaScript round trip moved.
 */
app(\App\Services\SettingsService::class)
    ->set('currency_symbol', 'AED<img src=/uploads/js/injected.png onerror=alert(7)> ');

\Illuminate\Support\Facades\Cache::flush();

echo "js seed: menu {$menu->id} product {$product->slug}\n";
