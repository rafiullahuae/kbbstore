<?php

declare(strict_types=1);

/**
 * HOMEPAGE BRANDS — AN IMAGE PER BRAND, AND A LOOK PER DEVICE.     (Lane BS)
 *
 * The owner, 5 October: "for brands section on homepage, for desktop i would
 * need to upload custom image for each brand upon brand selection in the edit
 * mode of homepage content. and in mobile i should have option to keep logo +
 * image or only image or only text. keep by default only text. and for
 * desktop image + name, no logo! include this function in the same edit
 * popup. must be super light and should work with blazing speed".
 *
 * Appearance → Homepage content → Top brands → Edit content:
 *   Brands tab   each picked brand: Choose image / Change / Remove, ↑ ↓, ×
 *   Layout tab   Look · laptop (Image + name — no logo)
 *                Look · phone  (Logo + image | Image only | Text only)
 *
 * Every case says what the defect looked like and how to turn it red.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Services\HomepageContent;
use App\Services\HomepageHub;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Support\HomeSections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\HomepageContentAdminRoutes;
use Tests\Support\HomepageHubRoutes;

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
});

function bsWrite(array $values): void
{
    ModuleSchema::write(app(SettingsService::class), 'homepage_content', HomepageContent::SCHEMA, $values);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function bsHome(): string
{
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
    Cache::flush();

    return test()->get('/')->assertOk()->getContent();
}

function bsSection(string $html): string
{
    return preg_match('#<section class="sec hs hs-brands\b.*?</div></section>#s', $html, $m) === 1 ? $m[0] : '';
}

/** One brand <a>, by its link. */
function bsCard(string $section, Brand $brand): string
{
    return preg_match('#<a class="hs-brand[^"]*" href="'.preg_quote($brand->url(), '#').'">.*?</a>#s', $section, $m) === 1 ? $m[0] : '';
}

function bsBrand(string $name, array $extra = []): Brand
{
    static $n = 0;
    $n++;

    $b = Brand::create(array_merge(['slug' => 'bs-'.$n.'-'.\Illuminate\Support\Str::slug($name), 'name' => $name], $extra));
    Product::create(['slug' => 'bs-p-'.$n.'-'.uniqid(), 'name' => $name.' Toner', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'stock_status' => 'instock', 'price' => 3000, 'total_sales' => 100000 - $n, 'brand_id' => $b->id]);

    return $b;
}

/* ═══ 1. THE DEFAULTS HE ASKED FOR ════════════════════════════════════════ */

it('ships image + name on a laptop and the names alone on a phone, with no logo anywhere', function () {
    /*
     * THE DEFECT, on the shop: a phone grid of logos he asked to replace with
     * text ("keep by default only text"), and a laptop card that falls back to
     * the brand's LOGO ("for desktop image + name, no logo!").
     *
     * MUTATION (RUN): set home_br_layout_m's default back to 'logo_image', or
     * home_br_layout_d's to 'image_logo' → red.
     */
    $logoOnly = bsBrand('Logo Only', ['logo' => '/uploads/bs/logo.png']);
    $photo = bsBrand('Photo Brand', ['logo' => '/uploads/bs/p-logo.png', 'banner' => ['image' => '/uploads/bs/banner.webp', 'image_alt' => 'Photo Brand shelf']]);

    $sec = bsSection(bsHome());

    expect($sec)->toContain('class="sec hs hs-brands hs-brm-text ')
        ->and(HomeSections::brands([])['look_d'])->toBe('image')
        ->and(HomeSections::brands([])['look_m'])->toBe('text')
        // No logo, on either device: not as a tile, not on the laptop panel.
        ->and($sec)->not->toContain('/uploads/bs/logo.png')
        ->and($sec)->not->toContain('/uploads/bs/p-logo.png')
        ->and($sec)->not->toContain('has-logo')
        ->and(bsCard($sec, $logoOnly))->toContain('<span class="hs-blb"><b>Logo Only</b></span>')
        ->and(bsCard($sec, $logoOnly))->not->toContain('<img')
        // Image + name on the laptop.
        ->and(bsCard($sec, $photo))->toContain('src="/uploads/bs/banner.webp" alt="Photo Brand shelf"')
        ->and(bsCard($sec, $photo))->toContain('<b>Photo Brand</b>');
});

it('keeps the previous laptop look one select away', function () {
    // MUTATION: drop the `image_logo` arm of $hsPanelLogo → no logo, red.
    $logoOnly = bsBrand('Logo Only', ['logo' => '/uploads/bs/logo.png']);
    bsWrite(['home_br_layout_d' => 'image_logo']);

    expect(bsCard(bsSection(bsHome()), $logoOnly))->toContain('src="/uploads/bs/logo.png" class="hs-bph-logo" alt="Logo Only"');
});

/* ═══ 2. AN IMAGE PER BRAND ════════════════════════════════════════════════ */

it('draws the image chosen for a brand ahead of its banner, and falls back for the rest', function () {
    /*
     * THE DEFECT: the owner chooses a picture for a brand and the card still
     * shows the banner (or nothing).
     * MUTATION (RUN): read `$hsOwn` as '' in hs-brands → red on the own image.
     */
    $a = bsBrand('Alpha', ['banner' => ['image' => '/uploads/bs/a-banner.webp']]);
    $b = bsBrand('Beta', ['banner' => ['image' => '/uploads/bs/b-banner.webp']]);
    $c = bsBrand('Gamma');
    bsWrite(['home_br_picks' => $a->id.','.$b->id.','.$c->id,
        'home_br_imgs' => json_encode([$a->id => '/storage/media/alpha-card.webp', $c->id => 'https://cdn.example.test/g.jpg'])]);

    $sec = bsSection(bsHome());

    expect(bsCard($sec, $a))->toContain('src="/storage/media/alpha-card.webp" alt="Alpha"')
        ->and(bsCard($sec, $a))->not->toContain('a-banner')
        ->and(bsCard($sec, $b))->toContain('src="/uploads/bs/b-banner.webp"')
        ->and(bsCard($sec, $c))->toContain('src="https://cdn.example.test/g.jpg"')
        ->and(bsCard($sec, $a))->toContain('class="hs-brand has-img"');
});

it('stores only checked pictures under real brand ids, and prints them escaped', function () {
    /*
     * THE DEFECT: a setting reaching an src unchecked — `javascript:`, a
     * protocol-relative `//evil`, a quote that closes the attribute — or a
     * key that is not a brand id.
     * MUTATION (RUN): return `$path` instead of image($path) in brandImages()
     * → javascript: and the quote survive, red.
     */
    $clean = HomeSections::cleanBrandImages(json_encode([
        '7' => '/storage/media/ok.webp',
        '8' => 'javascript:alert(1)',
        '9' => '//evil.test/x.png',
        '10' => '/a.png" onerror="alert(1)',
        '11' => ['nested'],
        '12' => 'data:image/svg+xml;base64,PHN2Zz4=',
        '-3' => '/neg.png',
        'x' => '/word.png',
        '0' => '/zero.png',
        '2' => 'https://cdn.example.test/two.jpg',
    ]));

    expect($clean)->toBe('{"2":"https://cdn.example.test/two.jpg","7":"/storage/media/ok.webp"}')
        ->and(HomeSections::cleanBrandImages('not json'))->toBe('')
        ->and(HomeSections::cleanBrandImages(''))->toBe('')
        ->and(HomeSections::cleanBrandImages('[1,2]'))->toBe('')
        ->and(HomeSections::cleanBrandImages(['5' => '/five.png']))->toBe('{"5":"/five.png"}');

    // Through the screen's own save, under the hub's capability.
    HomepageContentAdminRoutes::wire(app());
    HomepageHubRoutes::wire(app());
    $brand = bsBrand('Saved');
    $admin = AdminUser::create(['name' => 'BS', 'email' => 'bs-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']);
    test()->actingAs($admin, 'admin')->postJson('/admin-api/homepage/content', ['copy' => [
        'home_br_picks' => (string) $brand->id,
        'home_br_imgs' => json_encode([$brand->id => '/storage/media/s.webp?v=1&w=2', 99 => 'javascript:alert(1)']),
        'home_br_layout_m' => '<script>',
    ]])->assertOk();

    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
    $c = HomeSections::settings();
    expect($c['home_br_imgs'])->toBe('{"'.$brand->id.'":"/storage/media/s.webp?v=1&w=2"}')
        // A select keeps one of its own options or the default.
        ->and($c['home_br_layout_m'])->toBe('text');

    $card = bsCard(bsSection(bsHome()), $brand);
    expect($card)->toContain('src="/storage/media/s.webp?v=1&amp;w=2"')
        ->and($card)->not->toContain('javascript:');
});

it('reads a hand-edited row cleaned, and a look that is not an option as the default', function () {
    // MUTATION: drop the LOOK_CLASS map and print the stored value → red.
    $s = app(SettingsService::class);
    $s->set('home_br_imgs', '{"4":"javascript:alert(1)","5":"/ok.png"}');
    $s->set('home_br_layout_m', 'x" onmouseover="alert(1)');
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    $b = HomeSections::brands(HomeSections::settings());
    expect($b['imgs'])->toBe([5 => '/ok.png'])
        ->and($b['look_m'])->toBe('text')
        ->and($b['classes'])->toStartWith('hs hs-brands hs-brm-text ');
});

/* ═══ 3. THE THREE PHONE LOOKS, AND WHAT EACH DOWNLOADS ═══════════════════ */

it('gives a phone on Text only nothing to download, and a laptop no phone logo', function () {
    /*
     * THE DEFECT: a phone showing names still fetching every brand photo —
     * an <img> under display:none is downloaded unless the browser honours
     * lazy loading there. A <picture> source for the phone width carrying an
     * inline blank means there is no URL to fetch. Measured in Chromium:
     * docs/bs-shots/NUMBERS.txt (0 brand pictures at 390 on Text only).
     *
     * MUTATION (RUN): drop the `@if ($hsLook === 'text')<source …>` from
     * hs-brands → red on the source count.
     */
    $a = bsBrand('Alpha', ['logo' => '/uploads/bs/a-logo.png', 'banner' => ['image' => '/uploads/bs/a.webp']]);
    $b = bsBrand('Beta', ['banner' => ['image' => '/uploads/bs/b.webp']]);

    $text = bsSection(bsHome());
    expect(substr_count($text, '<source media="(max-width:900px)" srcset="'.HomeSections::BLANK.'">'))->toBe(2)
        ->and($text)->not->toContain('hs-blogo');

    bsWrite(['home_br_layout_m' => 'image']);
    $img = bsSection(bsHome());
    expect($img)->toContain('class="sec hs hs-brands hs-brm-img ')
        ->and($img)->not->toContain('<source')
        ->and($img)->not->toContain('a-logo.png')
        ->and(bsCard($img, $b))->toContain('class="hs-brand has-img"');

    bsWrite(['home_br_layout_m' => 'logo_image']);
    $logo = bsSection(bsHome());
    expect($logo)->toContain('class="sec hs hs-brands hs-brm-logo ')
        ->and(bsCard($logo, $a))->toContain('class="hs-brand has-img has-logo"')
        // The logo is the phone's alone: a laptop is given the blank.
        ->and(bsCard($logo, $a))->toContain('<picture class="hs-blogo"><source media="(min-width:901px)" srcset="'.HomeSections::BLANK.'"><img src="/uploads/bs/a-logo.png"')
        ->and(bsCard($logo, $b))->not->toContain('hs-blogo')
        ->and(substr_count($logo, '<source media="(max-width:900px)"'))->toBe(0);
});

it('styles each phone look in CSS, with the text tiles still the tile-height select', function () {
    /*
     * THE DEFECT: a look the select offers that the stylesheet never draws —
     * Image only showing the old name tiles with the photo hidden.
     * MUTATION: delete the `.hs-brm-img .hs-bph` rule → red.
     */
    $css = file_get_contents(resource_path('css/kbb/kbb.css'));
    $phone = substr($css, strpos($css, "@media (max-width:900px){\n  .kbb-home .sec.hs>.wrap"));

    expect($phone)->toContain('.kbb-home .hs-brand{display:grid;place-items:center;height:var(--hs-br-th-m,54px);')
        ->and($phone)->toContain('.kbb-home .hs-brm-img .hs-bph,.kbb-home .hs-brm-logo .hs-bph{display:block}')
        // MEASURED IN CHROMIUM: without place-items:normal the tile's
        // `place-items:center` survives the switch to display:block, Chromium
        // applies justify-items to block children, and the empty photo box
        // shrinks to 0px wide — every image card 178×0 at 390, an empty band.
        // MUTATION (RUN): drop `place-items:normal` → red here, and 0px cards.
        ->and($phone)->toContain('.kbb-home .hs-brm-img .hs-brand,.kbb-home .hs-brm-logo .hs-brand{display:block;place-items:normal;height:auto;')
        ->and($phone)->toContain('.kbb-home .hs-brm-img .has-img .hs-blb,.kbb-home .hs-brm-logo .has-logo .hs-blb{display:none}')
        ->and($phone)->toContain('.kbb-home .hs-brm-logo .has-logo .hs-blogo{display:flex;')
        // The logo is hidden everywhere else, the laptop included.
        ->and($css)->toContain(".kbb-home .hs-blogo{display:none}\n");
});

/* ═══ 4. SPEED ════════════════════════════════════════════════════════════ */

it('costs the homepage the same queries with 3 brand images as with 40', function () {
    /*
     * Rule 4: the images ride in the homepage's one settings read. A lookup
     * per brand (a media row, a brand column) would grow with the list.
     * MUTATION: look each picture up with Brand::find() in the partial → the
     * two counts differ, red.
     */
    $count = function (int $n): int {
        Brand::query()->delete();
        $imgs = [];
        for ($i = 0; $i < $n; $i++) {
            $imgs[bsBrand('Q'.$n.'-'.$i)->id] = '/storage/media/q'.$i.'.webp';
        }
        bsWrite(['home_br_imgs' => json_encode($imgs), 'home_br_count_d' => '24', 'home_br_layout_m' => 'logo_image']);
        bsHome();
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/')->assertOk();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    $three = $count(3);
    $forty = $count(40);

    expect($forty)->toBe($three);
});

/* ═══ 5. THE POPUP ════════════════════════════════════════════════════════ */

it('puts every control in the Brands popup: images beside the picks, both looks on Layout', function () {
    /*
     * THE DEFECT: a control built somewhere the owner has to hunt for, or the
     * image map drawn as a raw JSON text box.
     * MUTATION: drop `imgs` from HomepageHub::group()'s products pattern → the
     * images land on the Content tab, red.
     */
    expect(HomepageContent::TABS['brands'][2])->toContain('home_br_imgs')->toContain('home_br_layout_d')->toContain('home_br_layout_m')
        ->and(HomepageHub::group('home_br_imgs'))->toBe('products')
        ->and(HomepageHub::group('home_br_picks'))->toBe('products')
        ->and(HomepageHub::group('home_br_layout_d'))->toBe('layout')
        ->and(HomepageHub::group('home_br_layout_m'))->toBe('layout')
        ->and(HomepageContent::SCHEMA['home_br_imgs']['options'])->toBe(['picker' => 'per-pick', 'for' => 'home_br_picks'])
        ->and(HomepageContent::SCHEMA['home_br_layout_m']['options'])->toBe(['logo_image' => 'Logo + image', 'image' => 'Image only', 'text' => 'Text only']);

    HomepageContentAdminRoutes::wire(app());
    HomepageHubRoutes::wire(app());
    $admin = AdminUser::create(['name' => 'BS', 'email' => 'bs-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']);
    $j = test()->actingAs($admin, 'admin')->getJson('/admin-api/homepage-hub')->assertOk()->json();
    $row = collect($j['sections'])->firstWhere('key', 'brands');
    $f = collect($row['fields'])->keyBy('key');

    expect($f['home_br_imgs']['group'])->toBe('products')
        ->and($f['home_br_imgs']['options']['picker'])->toBe('per-pick')
        ->and($f['home_br_layout_m']['value'])->toBe('text');

    $src = file_get_contents(resource_path('views/admin/partials/homepage-hub.blade.php'));
    expect($src)->toContain("if (f.options && f.options.picker === 'per-pick') return '';")
        ->and($src)->toContain('ctl = per ? pickRowsHTML(list, chosen, f, per) : chipsHTML(')
        ->and($src)->toContain("window.kbbPickMedia({title: 'Brand image'")
        // The image saves with the section — no request of its own.
        ->and(substr_count($src, "req('POST'"))->toBe(5);
});
