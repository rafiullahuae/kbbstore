<?php

declare(strict_types=1);

/**
 * Appearance -> Occasion banner (Lane OHB): the homepage banner that shows
 * before / with the picture slider (the owner, 6 November 2026).
 */

use App\Http\Controllers\Admin\OccasionBannerController;
use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Cutouts\ProductCutouts;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\AdminRoles;
use App\Support\OccasionBanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ohbFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    request()->attributes->replace([]);
}

function ohbSet(array $c): void
{
    app(SettingsService::class)->set(OccasionBanner::KEY, $c);
    ohbFresh();
}

/** A pool row the way the controller snapshots it; no file is needed to draw it. */
function ohbItem(int $id, int $brand, float $cm, string $name = 'Item'): array
{
    return ['id' => $id, 'cm' => $cm, 'white' => false, 'name' => $name.' '.$id, 'brand' => 'Brand '.$brand, 'brand_id' => $brand,
        'image' => '/uploads/ohb/p'.$id.'.jpg', 'cut' => ['p' => 'uploads/ohb/p'.$id.'.jpg', 'w' => [240 => 80, 480 => 160]]];
}

function ohbPool(int $n, int $brands = 0): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $out[] = ohbItem($i, $brands > 0 ? 1 + ($i % $brands) : $i, 5 + ($i % 10));
    }

    return $out;
}

function ohbHome(string $path = '/'): string
{
    ohbFresh();

    return (string) test()->get($path)->getContent();
}

function ohbQueries(string $path = '/'): int
{
    ohbFresh();
    test()->get($path); // warm every cache the page fills once
    ohbFresh();
    $n = 0;
    DB::listen(function () use (&$n) { $n++; });
    test()->get($path);

    return $n;
}

function ohbSlider(): BannerSet
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();
    $s = BannerSet::create(['name' => 'Hero', 'slug' => 'ohb-'.uniqid(), 'status' => 'publish', 'position' => 0, 'kind' => 'slider', 'show_dots' => true, 'show_arrows' => true]);
    foreach ([1, 2] as $i) {
        BannerCard::create(['banner_set_id' => $s->id, 'image' => 'uploads/banners/ohb-'.$i.'.jpg', 'image_w' => 1920, 'image_h' => 1028,
            'alt' => 'Picture '.$i, 'button_url' => '/shop/', 'position' => $i, 'status' => 'publish']);
    }
    app(SettingsService::class)->set('module_settings', null);
    DB::table('module_toggles')->updateOrInsert(['module' => 'cards_banner'], ['enabled' => true]);
    DB::table('module_settings')->updateOrInsert(['module' => 'cards_banner', 'key' => 'set'], ['value' => (string) $s->id]);
    ohbFresh();

    return $s;
}

/* ═══════════════════════════════════ shipped off ═══════════════════════════ */

it('draws nothing while Display is Slider only, which is what it ships as', function () {
    /*
     * THE DEFECT this guards: a new homepage section that appears the moment
     * the package applies. view() answers null for no setting and for
     * `slider`, and the page then prints no `ohb` byte at all
     * (StorefrontEnglishUnchangedTest pins the whole page byte for byte).
     * MUTATION: default `display` to 'banner' in OccasionBanner::defaults() or
     * sanitize() and this is red.
     */
    expect(OccasionBanner::view([]))->toBeNull()
        ->and(OccasionBanner::view([OccasionBanner::KEY => ['display' => 'slider', 'pool' => ohbPool(5)]]))->toBeNull()
        ->and(OccasionBanner::view([OccasionBanner::KEY => ['display' => 'nonsense']]))->toBeNull()
        ->and(OccasionBanner::defaults()['display'])->toBe('slider');

    ohbSet(['display' => 'slider', 'pool' => ohbPool(5)]);
    // Not the bare letters 'ohb': the homepage carries a random CSRF token and an asset hash, and
    // one in ~800 runs a token spelled them (failed the 2.60.663 integration run, passed 5/5 alone).
    // A random token holds no quote and no dot, so these two still catch the banner's markup or CSS.
    expect(ohbHome())->not->toContain('class="ohb')->not->toContain('.ohb-');
});

/* ═══════════════════════════════════ normalising ═══════════════════════════ */

it('keeps every select to its own options, clamps numbers, trims words and refuses a script link', function () {
    /*
     * CLAUDE.md rule 5. A stored value is the attacker's only way in: a colour
     * that is not #rrggbb would end the style attribute, a javascript: link
     * would become an href. MUTATION: drop the preg_match on colours, or the
     * Banners::safeUrl() on the link, and this is red.
     */
    $c = OccasionBanner::sanitize([
        'display' => 'everything', 'layout' => 'Z', 'template' => 'xmas', 'count' => 99, 'per_brand' => 0,
        'colours' => ['acc' => 'red;background:url(x)', 'ink' => '#ABCDEF'],
        'link' => 'javascript:alert(1)',
        'en' => ['title' => "  BIG\x01 SALE  ", 'line' => str_repeat('x', 500)],
        'pool' => [['id' => 3, 'cm' => 900, 'cut' => ['p' => '../../.env', 'w' => [480 => 10]]], ['id' => 3], ['id' => -1], 'junk'],
    ]);

    expect($c['display'])->toBe('slider')
        ->and($c['layout'])->toBe('G')
        ->and($c['template'])->toBe('1111')
        ->and($c['count'])->toBe(5)
        ->and($c['per_brand'])->toBeFalse()
        ->and($c['colours']['acc'])->toBe('#e3246e')
        ->and($c['colours']['ink'])->toBe('#abcdef')
        ->and($c['link'])->toBe('')
        ->and($c['en']['title'])->toBe('BIG  SALE')
        ->and(mb_strlen($c['en']['line']))->toBe(160)
        ->and($c['pool'])->toHaveCount(1)
        ->and($c['pool'][0]['cm'])->toBe(40.0)
        ->and($c['pool'][0]['cut'])->toBeNull();
});

/* ═══════════════════════════════════ the storefront ════════════════════════ */

it('draws the banner, escaped, with five ready products from five brands, when Display is Banner only', function () {
    /*
     * THE DEFECT: a banner that prints a setting raw, or shows two products of
     * one brand while other brands wait. MUTATION: print the title with {!! !!}
     * and the <script> assertion is red; drop the per-brand pass in pick() and
     * the brand count is red (the pool below is ten products of only two
     * brands, plus five lone brands).
     */
    $pool = array_merge(ohbPool(10, 2), [ohbItem(21, 31, 9), ohbItem(22, 32, 9), ohbItem(23, 33, 9)]);
    $pool[] = ['id' => 30, 'cm' => 10, 'brand_id' => 34, 'name' => 'No cut-out', 'brand' => 'x', 'cut' => null];
    ohbSet(['display' => 'banner', 'layout' => 'H', 'template' => 'uae', 'pool' => $pool,
        'en' => ['label' => 'L', 'num' => '55', 'title' => '<script>x</script>', 'offer' => 'O', 'line' => 'B', 'cta' => 'Go']]);

    $html = ohbHome();

    expect($html)->toContain('<div class="ohb ohb-H"')
        ->and($html)->toContain('&lt;script&gt;x&lt;/script&gt;')
        ->and($html)->not->toContain('<script>x</script>')
        ->and($html)->toContain('<div class="ohb-halo"')
        ->and($html)->toContain('--acc:#00732f')
        ->and(substr_count($html, 'class="ohb-p '))->toBe(5)
        ->and($html)->not->toContain('No cut-out');

    preg_match_all('/alt="Item (\d+)"/', $html, $m);
    $brands = array_map(fn ($id) => collect($pool)->firstWhere('id', (int) $id)['brand_id'], $m[1]);
    expect(count(array_unique($brands)))->toBe(5);

    // The images carry the cut-out srcset, sizes, their own box and async decode.
    expect($html)->toMatch('#<img src="[^"]*/img-cache/cut/480/uploads/ohb/p\d+\.jpg\.webp" srcset="[^"]*/240/[^"]* 80w, [^"]*/480/[^"]* 160w" sizes="\(max-width:767px\) \d+px, \d+px" width="160" height="480" alt="Item \d+" decoding="async"#');
});

it('draws a mini smaller than a stick, whichever five come up', function () {
    /*
     * The owner: "the mini must be smaller than the Medicube stick". One cm
     * scale for the whole pool: the pool's tallest is 62% of the banner.
     * MUTATION: scale by the tallest of the five SHOWN instead of the pool, or
     * give every product its slot's design height, and this is red.
     */
    $pool = [ohbItem(1, 1, 7.5, 'Mini'), ohbItem(2, 2, 10, 'Stick'), ohbItem(3, 3, 15, 'Bottle')];
    $placed = OccasionBanner::place($pool, [$pool[0], $pool[1]]);
    $h = collect($placed)->mapWithKeys(fn ($p) => [$p['item']['id'] => $p['h']]);

    expect($h[1])->toBe(31.0)
        ->and($h[2])->toBe(41.3)
        ->and($h[1])->toBeLessThan($h[2]);

    // The tallest takes the slot designed for the tallest (2), the smallest the smallest.
    $five = array_merge($pool, [ohbItem(4, 4, 14), ohbItem(5, 5, 13)]);
    $slots = collect(OccasionBanner::place($five, $five))->mapWithKeys(fn ($p) => [$p['item']['id'] => $p['slot']]);
    expect($slots[3])->toBe(2)->and($slots[1])->toBe(0);
});

it('makes the banner the slider\'s first slide, at the slider\'s own frame, when Display is Both', function () {
    /*
     * THE DEFECT: a second banner stacked above the slider (two heights, two
     * frames) instead of one carousel whose slide 1 is the banner. MUTATION:
     * drop the echo in slider-banner.blade.php and the first-slide assertion
     * is red; forget $ohbOff on the bars and there are two `is-on` bars.
     */
    ohbSlider();
    ohbSet(['display' => 'both', 'pool' => ohbPool(5)]);

    $html = ohbHome();
    preg_match('#<div class="kbbs-tr">\s*<div class="kbbs-s"[^>]*aria-label="([^"]*)"><style>#', $html, $m);

    expect($m)->not->toBeEmpty()
        ->and($m[1])->toContain('1')->toContain('3')
        ->and(substr_count($html, 'class="kbbs-s"'))->toBe(3)
        ->and(substr_count($html, '<div class="ohb ohb-G"'))->toBe(1)
        ->and(substr_count($html, '<button class="kbbs-bar is-on"'))->toBe(1)
        ->and($html)->toContain('data-kbbs-go="2"')
        ->and($html)->toContain('.kbbs-s .ohb{height:100%;aspect-ratio:auto;max-height:none}');

    // Banner only: the slider goes and the banner keeps the section.
    ohbSet(['display' => 'banner', 'pool' => ohbPool(5)]);
    $only = ohbHome();
    expect($only)->not->toContain('class="kbbs ')
        ->and(substr_count($only, '<div class="ohb ohb-G"'))->toBe(1);

    // Slider only: the slider exactly as before, no banner.
    ohbSet(['display' => 'slider', 'pool' => ohbPool(5)]);
    $slider = ohbHome();
    expect($slider)->toContain('class="kbbs ')->not->toContain('class="ohb')->not->toContain('.ohb{')
        ->and(substr_count($slider, 'class="kbbs-s"'))->toBe(2);
});

it('speaks Arabic on /ar and mirrors the design', function () {
    /*
     * MUTATION: read $c['en'] whatever the locale in view() and this is red.
     */
    app(SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, '1');
    app(SettingsService::class)->set(\App\Support\Locale::SETTING_RTL, '1');
    ohbSet(['display' => 'banner', 'template' => 'ny', 'pool' => ohbPool(5)]);

    $html = ohbHome('/ar/');

    expect($html)->toContain('تخفيضات العام الجديد')
        ->and($html)->toContain('[dir=rtl] .ohb-copy{left:auto;right:clamp(18px,5vw,96px)}');
});

it('costs the homepage no query, and the same with 3 products in the pool as with 40', function () {
    /*
     * The page reads the banner out of the settings map it already holds; the
     * snapshot in the setting means no product is looked up. MUTATION: load
     * the pool's products from the table in view() and the on/off counts
     * differ; load them one by one and 3 vs 40 differ too.
     */
    $off = ohbQueries();

    ohbSet(['display' => 'banner', 'pool' => ohbPool(3)]);
    $three = ohbQueries();

    ohbSet(['display' => 'banner', 'pool' => ohbPool(40, 12)]);
    $forty = ohbQueries();

    expect($three)->toBe($off)->and($forty)->toBe($three);
});

it('animates only transform and opacity, and stops for reduced motion and for Animation off', function () {
    /*
     * CLAUDE.md speed rules: composited movement only. MUTATION: animate
     * `top`, `height` or `background-position` in a keyframe and this is red.
     */
    $css = OccasionBanner::css();
    preg_match_all('/@keyframes \w+\{(.*?\})\}/', $css, $m);
    foreach ($m[1] as $body) {
        preg_match_all('/([a-z-]+):/', $body, $props);
        expect(array_diff(array_unique($props[1]), ['transform', 'opacity']))->toBe([]);
    }
    expect($css)->toContain('@media (prefers-reduced-motion:reduce){')
        ->and($css)->toContain('.ohb-still .ohb-bg i')
        // phone layout only below 768 so a phone on its side gets the computer one
        ->and($css)->toContain('@media (max-width:767px){')
        ->and($css)->not->toContain('max-width:844')
        // a newer CSS function never sits next to var() (CLAUDE.md landmine)
        ->and($css)->not->toContain('round(')->not->toContain('color-mix(');

    ohbSet(['display' => 'banner', 'animate' => false, 'pool' => ohbPool(5)]);
    expect(ohbHome())->toContain('<div class="ohb ohb-G ohb-still"');
});

/* ═══════════════════════════════════ the admin ═════════════════════════════ */

it('gives every endpoint its own capability and fails closed', function () {
    /*
     * MUTATION: remove the four occasion-banner rules from
     * AdminCapabilities::RULES and forPath() answers null (owner-only) --
     * red here, and an editor is locked out of the screen.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/occasion-banner'))->toBe('occasionbanner.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/occasion-banner/products'))->toBe('occasionbanner.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/occasion-banner'))->toBe('occasionbanner.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/occasion-banner/cutout'))->toBe('occasionbanner.manage')
        ->and(AdminCapabilities::forPath('DELETE', 'admin-api/occasion-banner/anything'))->toBe('occasionbanner.manage')
        ->and(AdminCapabilities::roleCan('editor', 'occasionbanner.manage'))->toBeTrue()
        ->and(AdminCapabilities::roleCan('support', 'occasionbanner.view'))->toBeFalse()
        ->and(AdminRoles::labels())->toHaveKeys(['occasionbanner.view', 'occasionbanner.manage']);
});

it('snapshots the pool from the table on save and never trusts what the browser says a product is', function () {
    /*
     * THE DEFECT: the browser could name a product anything, or claim a
     * cut-out that does not exist, and the homepage would print it. save()
     * keeps only id, cm and the white tick and rebuilds the rest from the
     * products table and the disk. MUTATION: keep the posted `name`/`cut` and
     * this is red.
     */
    $brand = Brand::create(['name' => 'Anua', 'slug' => 'anua-'.Str::random(5)]);
    $p = Product::create(['slug' => 'ohb-'.Str::random(6), 'name' => 'Anua Mist Mini', 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'image' => '/uploads/ohb-test/none.jpg', 'brand_id' => $brand->id]);

    $res = app(OccasionBannerController::class)->save(Request::create('/x', 'POST', ['config' => [
        'display' => 'banner',
        'pool' => [['id' => $p->id, 'cm' => 7.5, 'white' => true, 'name' => '<b>Fake</b>', 'cut' => ['p' => 'uploads/x.jpg', 'w' => [480 => 100]]], ['id' => 999999]],
    ]]));
    $stored = OccasionBanner::config([OccasionBanner::KEY => app(SettingsService::class)->get(OccasionBanner::KEY)]);

    expect($res->getData(true)['ok'])->toBeTrue()
        ->and($stored['pool'])->toHaveCount(1)
        ->and($stored['pool'][0]['name'])->toBe('Anua Mist Mini')
        ->and($stored['pool'][0]['brand'])->toBe('Anua')
        ->and($stored['pool'][0]['cm'])->toBe(7.5)
        ->and($stored['pool'][0]['white'])->toBeTrue()
        ->and($stored['pool'][0]['cut'])->toBeNull();
});

it('prepares a cut-out on request and puts it into the stored pool', function () {
    /*
     * "Prepare cut-outs": one product per request. MUTATION: drop the pool
     * update in cutout() and the stored snapshot stays null, so the shop never
     * shows the product.
     */
    $dir = public_path('uploads/ohb-test');
    @mkdir($dir, 0755, true);
    $im = imagecreatetruecolor(200, 300);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 70, 40, 130, 260, imagecolorallocate($im, 30, 90, 200));
    imagejpeg($im, $dir.'/blue.jpg', 92);

    $p = Product::create(['slug' => 'ohb-'.Str::random(6), 'name' => 'Blue Bottle', 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'image' => '/uploads/ohb-test/blue.jpg']);
    ohbSet(['display' => 'banner', 'pool' => [['id' => $p->id, 'cm' => 15, 'name' => 'Blue Bottle', 'cut' => null]]]);

    try {
        $res = app(OccasionBannerController::class)->cutout(Request::create('/x', 'POST', ['id' => $p->id]));
        $stored = OccasionBanner::config([OccasionBanner::KEY => app(SettingsService::class)->get(OccasionBanner::KEY)]);

        expect($res->getData(true)['ok'])->toBeTrue()
            ->and($stored['pool'][0]['cut']['p'])->toBe('uploads/ohb-test/blue.jpg')
            ->and($stored['pool'][0]['cut']['w'])->toHaveKeys([240, 480]);

        ohbFresh();
        expect(ohbHome())->toContain('/img-cache/cut/480/uploads/ohb-test/blue.jpg.webp');
    } finally {
        ProductCutouts::forget('/uploads/ohb-test/blue.jpg');
        @unlink($dir.'/blue.jpg');
        @rmdir($dir);
    }
});

it('hands the screen everything its in-browser preview needs, in one answer', function () {
    /*
     * "no request per keystroke": the preview is built from show()'s answer.
     * MUTATION: drop `css` or `templates` from adminCatalogue() and the screen
     * cannot draw without asking again -- red here.
     */
    $j = app(OccasionBannerController::class)->show(Request::create('/x', 'GET'))->getData(true);

    expect($j['catalogue']['css'])->toBe(OccasionBanner::css())
        ->and(array_column($j['catalogue']['templates'], 'key'))->toBe(['1111', 'uae', 'ny', 'super'])
        ->and(array_column($j['catalogue']['layouts'], 'key'))->toBe(['G', 'H', 'I'])
        ->and($j['config']['display'])->toBe('slider');

    $screen = file_get_contents(resource_path('views/admin/partials/occasion-banner-screen.blade.php'));
    expect(substr_count($screen, 'window.kbbAddNavEntry({'))->toBe(1)
        ->and($screen)->not->toContain('setInterval')
        ->and($screen)->not->toContain('getBoundingClientRect');
});

it('never draws the same photo twice in one banner', function () {
    // Defect: two pool products sharing one picture (a variant, a set and its
    // single) put the same jar side by side. MUTATION: drop the photo filter in
    // pick() and this is red.
    $row = fn (int $id, string $img, int $brand) => ['id' => $id, 'image' => $img, 'brand_id' => $brand, 'brand' => 'B'.$brand, 'cut' => ['h' => 480], 'cm' => 10.0];
    $pool = [$row(1, '/a.webp', 1), $row(2, '/a.webp', 2), $row(3, '/b.webp', 3), $row(4, '/c.webp', 4)];
    for ($i = 0; $i < 20; $i++) {
        $imgs = array_column(\App\Support\OccasionBanner::pick($pool, 5, true), 'image');
        expect(count($imgs))->toBe(count(array_unique($imgs)))->and(count($imgs))->toBe(3);
    }
});
