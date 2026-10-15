<?php
/*
 * Lane ARC -- a preview database shaped like the LIVE catalogue.
 *
 * PREVIEW DATABASE ONLY (tools/arc-preview.sh); nothing here reaches the shop.
 * Runs after tools/ar-seed.php (Arabic on, mirrored on). Builds, from the live
 * snapshot in storage/arc-logs (the public /api/products, the sitemap and each
 * brand / collection page, 15 October 2026):
 *
 *   - 761 products with their live slugs, names, prices and short lines;
 *   - 95 brands and 48 categories with their live names and descriptions;
 *   - the live primary menu (Trending Brands, All Brands, Skincare, ...);
 *   - the static pages and the eleven blog posts;
 *   - the owner's own homepage wording (Best-Sellers, Top Brands, the three
 *     Cards banner cards, the bundles line, Spotted, Under AED 54 ...);
 *   - every shipped Arabic interface draft APPROVED (the owner's "Approve all");
 *   - translation_highlight_missing ON, so every untranslated string shows ⟪⟫.
 *
 * Then it runs Lane ARC's three migrations' up() against it, which is what the
 * package does on the live server. KBB_ARC_SKIP=1 stops before that, for the
 * BEFORE measurement.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

$S = base_path('storage/arc-logs');
$live = json_decode(file_get_contents($S.'/live-products.json'), true);
$harvest = json_decode(file_get_contents($S.'/harvest.json'), true);
$posts = json_decode(file_get_contents($S.'/api-posts.json'), true);
$sv = app(\App\Services\SettingsService::class);

$pic = function (int $i): string {
    $pal = [['#F7C6D4', '#E0567B'], ['#CDE7D6', '#3E8E62'], ['#FFE6B8', '#E0922F'], ['#D9D2F2', '#7B6CF0'],
        ['#C9E6F2', '#2F84A8'], ['#F2D5C9', '#B8613C'], ['#E3F2C9', '#7A9E35'], ['#F2C9E8', '#A83C8C']];
    [$a, $b] = $pal[$i % count($pal)];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="300"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/></linearGradient></defs><rect width="240" height="300" fill="url(#g)"/></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

/* The lane-ar fixture products are not the live catalogue: hidden, not deleted
   (their order lines and the set keep pointing at them). */
DB::table('products')->where('slug', 'like', 'lanear-%')->update(['is_visible' => false, 'status' => 'draft']);

/* ── BRANDS ── */
$brandIds = [];
$brandByName = [];
foreach ($harvest['brands'] as $slug => $b) {
    $row = Brand::updateOrCreate(['slug' => $slug], [
        'name' => html_entity_decode((string) $b['name']),
        'description' => $b['description'] !== null ? html_entity_decode(strip_tags((string) $b['description'])) : null,
    ]);
    $brandIds[$slug] = $row->id;
    $brandByName[strtolower(preg_replace('/[^a-z0-9]/i', '', html_entity_decode((string) $b['name'])))] = $row->id;
}
$brandFor = function (string $name) use ($brandByName): ?int {
    $flat = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
    $best = null;
    $len = 0;
    foreach ($brandByName as $key => $id) {
        if ($key !== '' && str_starts_with($flat, $key) && strlen($key) > $len) {
            [$best, $len] = [$id, strlen($key)];
        }
    }
    if ($best === null && str_starts_with($flat, 'ma') && str_contains(strtolower($name), 'nyo')) {
        $best = $brandByName['manyo'] ?? null;
    }

    return $best ?? ($brandByName['vtcosmetics'] ?? null);
};

/* ── CATEGORIES ── */
$catIds = [];
foreach ($harvest['categories'] as $slug => $c) {
    $catIds[$slug] = Category::updateOrCreate(['slug' => $slug], [
        'name' => html_entity_decode((string) $c['name']),
        'description' => $c['description'] !== null ? '<p>'.$c['description'].'</p>' : null,
    ])->id;
}
$rules = [
    'toners' => '/toner/i', 'face-serums' => '/serum|ampoule|essence|booster shot/i', 'moisturizers' => '/cream|moistur|lotion|balm/i',
    'sunscreens' => '/sun|spf/i', 'face-masks' => '/mask|patch/i', 'cleansers' => '/cleans|foam|wash|cleanser/i',
    'lip-care' => '/lip/i', 'hair-care' => '/shampoo|hair|scalp|conditioner/i', 'skincare-sets' => '/set\b|kit\b|duo\b|trio\b/i',
    'eye-care' => '/eye/i', 'body-care' => '/body/i', 'beauty-devices' => '/booster pro|device|roller|led|steamer|brush/i',
    'exfoliators' => '/peel|exfol|pad\b|bha|aha/i',
];

/* ── PRODUCTS: the live 761, by slug ── */
$now = now();
foreach ($live as $i => $p) {
    $price = (int) $p['price'];
    $sale = $p['sale_price'] !== null && (int) $p['sale_price'] < $price ? (int) $p['sale_price'] : null;
    $attrs = [
        'name' => $p['name'], 'price' => $price > 0 ? $price : 9900, 'sale_price' => $sale,
        'short_description' => $p['short_description'], 'brand_id' => $brandFor($p['name']),
        'image' => $pic($i), 'status' => 'publish', 'is_visible' => true,
        'stock_status' => $p['stock_status'] ?: 'instock', 'type' => 'simple',
        'rating' => (float) ($p['rating'] ?? 0), 'review_count' => (int) $p['review_count'],
        'total_sales' => 1000 - $i, 'updated_at' => $now,
    ];
    $id = DB::table('products')->where('slug', $p['slug'])->value('id');
    if ($id === null) {
        $id = DB::table('products')->insertGetId($attrs + ['slug' => $p['slug'], 'created_at' => $now->copy()->subDays($i % 90)]);
    } else {
        DB::table('products')->where('id', $id)->update($attrs);
    }
    $links = ['skincare'];
    foreach ($rules as $cat => $re) {
        if (preg_match($re, $p['name'])) {
            $links[] = $cat;
        }
    }
    if ($sale !== null) {
        $links[] = 'super-sale';
    }
    if (($sale ?? $price) <= 5400) {
        $links[] = 'under-aed-54';
    }
    foreach (array_unique($links) as $cat) {
        if (isset($catIds[$cat])) {
            DB::table('category_product')->updateOrInsert(['category_id' => $catIds[$cat], 'product_id' => $id]);
        }
    }
}

/* Only the live catalogue is on show: the fixtures other seeders leave behind
   (DemoCatalogueSeeder, lane-ar) are hidden, not deleted. */
$liveSlugs = array_column($live, 'slug');
DB::table('products')->whereNotIn('slug', $liveSlugs)->update(['is_visible' => false]);

/* The SKIN1004 sun serum carries the live product page's long prose, so the
   product page shows what phase 2 has not reached yet. */
DB::table('products')->where('slug', 'skin-1004-madagascar-centella-hyalu-cica-water-fit-sun-serum-50ml')->update([
    'description' => '<p><strong>Benefits:</strong></p><ul><li>Fitted with SPF 50+ PA++++, this serum-like sunscreen containing Hyaluronic Acid and Centella Asiatica Extract soothes and hydrates skin while protecting it from UV rays.</li><li>Fast-absorbing formula won\'t leave a sticky after-feel.</li></ul><p><strong>How to Use:</strong> Use at the last step of your morning skin care routine.</p><p><strong>Size:</strong> 50ml</p>',
    'ingredients' => '<p>Centella Asiatica (Gotu Kola) Extract(50%), Water, Glycerin, 1,2-Hexanediol, Pentaerythrityl Tetraisostearate, Caprylic/Capric Triglyceride.</p>',
]);

/* ── THE LIVE PRIMARY MENU ── */
$menu = Menu::updateOrCreate(['slug' => 'primary'], ['name' => 'Primary', 'location' => 'primary']);
MenuItem::where('menu_id', $menu->id)->delete();
$top = function (string $label, string $url, int $pos) use ($menu): MenuItem {
    return MenuItem::create(['menu_id' => $menu->id, 'label' => $label, 'url' => $url, 'position' => $pos]);
};
$kids = function (MenuItem $parent, array $rows) use ($menu): void {
    foreach ($rows as $n => [$label, $url]) {
        MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $parent->id, 'label' => $label, 'url' => $url, 'position' => $n]);
    }
};
$trending = ['Anua', 'Arencia', 'Axis - Y', 'Beauty of Joseon', 'BIODANCE', 'Celimax', 'Centellian24', 'Dr.Althea', 'Dr.Melaxin', 'EQQUALBERRY', 'Medicube', 'MOEV', 'numbuzin', 'SKIN 1004'];
$slugOfBrand = [];
foreach ($harvest['brands'] as $slug => $b) {
    $slugOfBrand[html_entity_decode((string) $b['name'])] = $slug;
}
$kids($top('Trending Brands', '/brands/', 0), array_map(fn ($n) => [$n, '/brands/'.($slugOfBrand[$n] ?? 'anua').'/'], $trending));
$all = array_values(array_filter(array_keys($slugOfBrand), fn ($n) => ! in_array($n, $trending, true)));
$kids($top('All Brands', '/brands/', 1), array_map(fn ($n) => [$n, '/brands/'.$slugOfBrand[$n].'/'], $all));
$kids($top('Skincare', '/collections/skincare/', 2), [['Cleansers', '/collections/cleansers/'], ['Exfoliators', '/collections/exfoliators/'],
    ['Toners', '/collections/toners/'], ['Face Serums', '/collections/face-serums/'], ['Eye Care', '/collections/eye-care/'],
    ['Face Masks', '/collections/face-masks/'], ['Moisturizers', '/collections/moisturizers/'], ['Lip Care', '/collections/lip-care/'],
    ['Sunscreens', '/collections/sunscreens/']]);
$kids($top('Shop by Skin Concern', '/collections/skincare/', 3), [['Acne & Blemish Care', '/collections/acne-blemish-care/'],
    ['Anti-aging', '/collections/anti-aging/'], ['Hydration & Glow', '/collections/hydration-glow/'], ['Pigmentation', '/collections/pigmentation/'],
    ['Pore Care', '/collections/pore-care/'], ['Redness & Sensitive Skin', '/collections/redness-sensitive-skin/'], ['Oily Skin', '/collections/oily-skin/']]);
foreach ([['Super Sale', '/super-sale/'], ['Skincare Sets', '/collections/skincare-sets/'], ['Hair Care', '/collections/hair-care/'],
    ['Body Care', '/collections/body-care/'], ['Beauty Devices', '/collections/beauty-devices/'], ['Everything Under 54 AED', '/everything-under-54-aed/']] as $n => [$l, $u]) {
    $top($l, $u, 4 + $n);
}

/* ── PAGES AND POSTS ── */
foreach (['privacy-policy' => 'Privacy Policy', 'terms-and-conditions' => 'Terms & Conditions', 'delivery' => 'Shipping & Delivery',
    'refund_returns' => 'Refund and Returns Policy', 'faqs' => 'Frequently Asked Questions', 'contact-us' => 'Contact Us', 'about' => 'About Us'] as $slug => $title) {
    Page::updateOrCreate(['slug' => $slug], ['title' => $title, 'status' => 'published',
        'content' => '<p>'.$title.' — the live page body (phase 2 of the Arabic content).</p>']);
}
foreach ($posts as $n => $p) {
    Post::updateOrCreate(['slug' => $p['slug']], [
        'title' => $p['title'], 'excerpt' => (string) $p['excerpt'], 'status' => 'published',
        'body' => '<p>'.e((string) ($p['excerpt'] ?: $p['title'])).'</p>', 'published_at' => now()->subDays(2 + $n * 5),
    ]);
}

/* ── THE OWNER'S OWN HOMEPAGE WORDING (as the live /ar shows it) ── */
foreach ([
    'home_bs_title' => 'Best-Sellers', 'home_bs_sub' => 'Good skin starts with the right favourites',
    'home_br_title' => 'Top Brands', 'home_br_sub' => 'Discover the best of Korean beauty',
    'home_tr_sub' => 'From viral finds to your next holy grail.', 'home_tr_btn' => 'View All',
    'home_u54_sub' => 'Limited-time prices on your K-beauty favorites.',
    'home_hb_sub' => 'Your beauty essentials, better together.', 'home_hb_btn_text' => 'All skincare sets',
    'spotted_home_sub' => 'See what everyone’s loving from K-Beauty Bliss',
    'translation_highlight_missing' => true,
] as $k => $v) {
    $sv->set($k, $v);
}
$set = \App\Models\BannerSet::updateOrCreate(['slug' => 'live-cards'], ['name' => 'Live cards', 'status' => 'publish', 'show_text' => true, 'show_button' => true]);
\App\Models\BannerCard::where('banner_set_id', $set->id)->delete();
foreach ([
    ['Glow on the Go!', 'Discover Anua’s PDRN-powered hydration for plumper, smoother, radiant-looking skin.', 'anua-pdrn-hyaluronic-acid-hydrating-capsule-mist-mini'],
    ['Medicube Vanilla Duo', 'The viral Deodorant & Body Mist Duo with a creamy, irresistible Vanilla Pistachio scent.', 'medicube-vanilla-deodorant-and-body-mist-duo'],
    ['Daily Shot of Radiance', 'Dual Vitamin C complex enhanced with Niacinamide, Glutathione, and Vitamin E.', 'arencia-vitamin-c-booster-shot'],
] as $n => [$h, $b, $slug]) {
    \App\Models\BannerCard::create(['banner_set_id' => $set->id, 'image' => $pic($n + 3), 'alt' => $n === 0 ? 'Anua’s PDRN mist and serum for plumper, smoother, radiant-looking skin' : '',
        'heading' => $h, 'body' => $b, 'button_label' => 'Shop Now', 'button_url' => '/product/'.$slug.'/', 'position' => $n, 'status' => 'publish',
        'image_w' => 1200, 'image_h' => 600, 'box_on' => true]);
}
$sv->setModule('cards_banner', true);
$sv->setModuleSetting('cards_banner', 'set', (string) $set->id);

/* ── THE OWNER'S "APPROVE ALL" (Translation → Progress) ── */
require __DIR__.'/ar-publish-drafts.php';

$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
\App\Services\Translation\TranslationStore::flush();

if (getenv('KBB_ARC_SKIP') !== '1') {
    foreach (['2027_11_03_100000_arabic_content_catalogue', '2027_11_03_100100_arabic_content_homepage_wording', '2027_11_03_100200_arabic_content_short_descriptions'] as $m) {
        $file = database_path('migrations/'.$m.'.php');
        if (is_file($file)) {
            (require $file)->up();
        }
    }
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
    \App\Services\Translation\TranslationStore::flush();
}

echo 'arc-seed: products ', DB::table('products')->where('is_visible', true)->count(),
    ', brands ', DB::table('brands')->count(), ', categories ', DB::table('categories')->count(),
    ', ar published ', DB::table('translations')->where('locale', 'ar')->where('status', 'published')->count(),
    ', ar drafts ', DB::table('translations')->where('locale', 'ar')->where('status', 'draft')->count(), "\n";
