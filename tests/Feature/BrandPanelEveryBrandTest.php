<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SiteLayout;
use App\Support\BrandPanel;
use Illuminate\Support\Facades\DB;
use Tests\Support\StorefrontAdminRoutes;

/*
 * THE PANEL ON EVERY BRAND PAGE, THE LOGO OFF, THE NAME AND THE DESCRIPTION
 * CENTRED, AND "READ MORE" AFTER TWO LINES.                         (Lane BR4)
 *
 * The owner, with the live Anua page beside the previews sent with 2.60.398:
 *
 *   "the brands pages are not loading as per these previews. and options at
 *    all. ... i want to turn off the logo by default, and give control make
 *    name positioning, and with description i want read more / read less
 *    after two lines, and centered align."
 *
 * THE DEFECT, ON THE SHOP. Store\BrandController::hero() returned Panel only
 * for a brand with NO page banner ("a brand with its own banner keeps the
 * Compact row under it"). Every brand the owner had given a banner -- Anua
 * among them -- drew the banner with its own heading and words on top and the
 * round logo alone under it: the old page, with none of the Panel's controls.
 * BR2 and BR3 photographed brands without a banner, so nobody saw it.
 *
 * MUTATIONS, each red here:
 *   · hero() given back its `&& $banner === null`                   → "root cause"
 *   · BrandController passing the banner to the view under Panel     → "one header"
 *   · forBrand() preferring header_image to the banner's picture     → "picture"
 *   · the partial printing the logo whatever `logo` says             → "logo off"
 *   · BrandPanel::LOGO_SHOWS back to ['on', 'off']                   → "logo off"
 *   · a QUIET_CHOICES entry for an alignment removed                 → "alignment"
 *   · the length check dropped from forBrand() (button always)       → "read more"
 *   · tabs.js measuring the text (scrollHeight & co.)                → "no measuring"
 *   · the migration's whereIn() widened to every value               → "migration"
 */

function br4Brand(array $extra = []): Brand
{
    $brand = Brand::create(array_merge([
        'name' => 'Anuabr4', 'slug' => 'anuabr4',
        'description' => 'Anuabr4 believes healthy skin comes from a relaxed mind.',
    ], $extra));

    Product::create([
        'slug' => 'br4-'.$brand->slug, 'name' => 'BR4 Product', 'brand_id' => $brand->id,
        'price' => 1000, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
    ]);

    return $brand;
}

/** The live Anua's shape: the owner's own page banner on, with its picture. */
function br4Banner(array $extra = []): array
{
    return array_merge(['enabled' => true, 'style' => 'full', 'image' => '/uploads/brands/anua-green.webp',
        'heading' => 'Banner Heading BR4', 'subheading' => 'Banner line BR4.'], $extra);
}

function br4Save(array $values): void
{
    app(SiteLayout::class)->save($values);
    Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
}

function br4Page(string $slug = 'anuabr4'): string
{
    return (string) test()->get("/brands/{$slug}/")->assertOk()->getContent();
}

function br4Section(string $html): string
{
    return preg_match('#<section class="brw-ph[^"]*" style="[^"]*"[^>]*>#', $html, $m) === 1 ? $m[0] : '';
}

/** The words of the panel, from its <section> to its end. */
function br4Panel(string $html): string
{
    $start = strpos($html, '<div class="brw-phw"');

    return $start === false ? '' : substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
}

function br4Css(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-brand-header.css'));
}

/** [laptop rules (min-width container), phone rules (max-width container)]. */
function br4CssDevices(): array
{
    $css = br4Css();
    $l = strpos($css, '@container (min-width:600px){');
    $p = strpos($css, '@container (max-width:599px){');

    return [substr($css, (int) $l, (int) strpos($css, "\n}\n", (int) $l) - $l), substr($css, (int) $p)];
}

const BR4_LONG = 'Anuabr4 believes that healthy skin does not depend solely on skin care products but also on having a relaxed '
    .'mind and regulated lifestyle. The brand focuses on choosing pure, organic ingredients to maximize effectiveness.';

/* ========================================================== root cause */

it('draws the Panel on a brand WITH the owner\'s page banner -- the live Anua -- not the banner and a logo under it', function () {
    br4Brand(['banner' => br4Banner()]);

    $html = br4Page();

    // MUTATION: give hero() back `&& $banner === null` and this page is the
    // banner component plus the Compact row -- no brw-ph at all.
    expect(\App\Http\Controllers\Store\BrandController::hero('panel'))->toBe('panel')
        ->and(br4Section($html))->not->toBe('')
        ->and($html)->toContain('<img class="brw-ph__img" src="/uploads/brands/anua-green.webp" alt=""')
        ->and($html)->toMatch('#<link rel="stylesheet" href="[^"]*kbb-brand-header[^"]*\.css"#')
        ->and($html)->not->toContain('<div class="brw-hero');
});

it('draws ONE header: no second banner, no title header, one <h1> -- the brand name -- and the banner\'s words not printed', function () {
    // header_image too, so the title header WOULD draw under Compact.
    br4Brand(['banner' => br4Banner(), 'header_image' => '/uploads/brands/imported.webp']);

    $html = br4Page();
    $body = substr($html, (int) strpos($html, '<body'));

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<h1 class="brw-ph__name" id="brw-ph-title">Anuabr4</h1>')
        ->and($html)->not->toContain('<section class="kbb-banner')
        ->and($html)->not->toContain('kbb-banner.css')
        ->and($html)->not->toContain('data-kbb-title-header')
        ->and($body)->not->toContain('Banner Heading BR4')
        // the brand has a description of its own, so the banner's line is not used
        ->and($body)->not->toContain('Banner line BR4.')
        ->and(substr_count($body, 'Anuabr4 believes healthy skin'))->toBe(1);

    // The control still puts the old page back.
    br4Save(['brand_hero' => 'compact']);
    expect(br4Page())->toContain('<section class="kbb-banner')->and(br4Page())->not->toContain('<section class="brw-ph');
});

it('takes the picture from the banner first, then header_image, then the brand\'s own shades', function () {
    // Both: the banner's (the one the pencil's "Banner picture" changes).
    br4Brand(['banner' => br4Banner(), 'header_image' => '/uploads/brands/imported.webp']);
    // Tint banner, no picture of its own: header_image.
    br4Brand(['name' => 'Tintbr4', 'slug' => 'tintbr4', 'banner' => br4Banner(['style' => 'tint', 'image' => null]), 'header_image' => '/uploads/brands/imported.webp']);
    // Neither: the no-picture ground, no broken <img>.
    br4Brand(['name' => 'Barebr4', 'slug' => 'barebr4', 'banner' => br4Banner(['image' => null, 'heading' => '', 'subheading' => ''])]);
    // A banner whose picture is not a safe address: the next in line.
    br4Brand(['name' => 'Jsbr4', 'slug' => 'jsbr4', 'banner' => ['enabled' => true, 'image' => 'javascript:alert(1)'], 'header_image' => '/uploads/brands/imported.webp']);

    // MUTATION: swap the two in forBrand() and the first is imported.webp.
    expect(br4Page())->toContain('src="/uploads/brands/anua-green.webp"')->not->toContain('imported.webp')
        ->and(br4Page('tintbr4'))->toContain('src="/uploads/brands/imported.webp"')
        ->and(br4Section(br4Page('barebr4')))->toContain('brw-ph--noimg')
        ->and(br4Page('barebr4'))->not->toContain('brw-ph__img')
        ->and(br4Page('jsbr4'))->toContain('src="/uploads/brands/imported.webp"')
        ->and(br4Page('jsbr4'))->not->toContain('javascript:alert');
});

it('uses the banner\'s line as the description only when the brand has none, escaped and printed once', function () {
    br4Brand(['description' => null, 'banner' => br4Banner(['subheading' => 'Pure & gentle <b>heartleaf</b>'])]);
    br4Brand(['name' => 'Emptybr4', 'slug' => 'emptybr4', 'description' => null, 'banner' => br4Banner(['heading' => '', 'subheading' => ''])]);

    $html = br4Page();
    $panel = br4Panel($html);
    $body = substr($html, (int) strpos($html, '<body'));

    expect($panel)->toContain('<div class="brw-ph__desc brw-desc">Pure &amp; gentle')
        ->and($panel)->not->toContain('<b>heartleaf</b>')
        ->and(substr_count($body, 'Pure &amp; gentle'))->toBe(1)
        ->and(substr_count($html, '<h1'))->toBe(1)
        // no heading, no line, no description: the name alone, no empty box
        ->and(br4Panel(br4Page('emptybr4')))->not->toContain('brw-ph__desc')
        ->and(substr_count(br4Page('emptybr4'), '<h1'))->toBe(1);
});

/* ============================================================= the logo */

it('shows no logo by default -- no circle, no gap -- and shows it when switched on, shop-wide or per brand', function () {
    br4Brand(['banner' => br4Banner(), 'logo' => '/uploads/brands/anua-logo.png']);
    br4Brand(['name' => 'Ownbr4', 'slug' => 'ownbr4', 'logo' => '/uploads/brands/own-logo.png', 'header_layout' => ['logo_show' => 'on']]);

    // MUTATION: print the logo whatever `logo` says and this panel has a circle.
    expect(SiteLayout::SCHEMA['brand_logo_show'][2])->toBeFalse()
        ->and(SiteLayout::SCHEMA['brand_logo_show_m'][2])->toBeFalse()
        ->and(br4Panel(br4Page()))->not->toContain('brw-logo')
        ->and(br4Section(br4Page()))->not->toContain('brw-ph--lg')
        // the brand's own switch beats the shop's Off
        ->and(br4Panel(br4Page('ownbr4')))->toContain('<img src="/uploads/brands/own-logo.png"')
        ->and(br4Section(br4Page('ownbr4')))->toContain(' brw-ph--lg-on')
        ->and(br4Section(br4Page('ownbr4')))->not->toContain('brw-ph--lgm-on');

    br4Save(['brand_logo_show' => true, 'brand_logo_show_m' => true]);
    expect(br4Panel(br4Page()))->toContain('<img src="/uploads/brands/anua-logo.png"')
        ->and(br4Section(br4Page()))->toContain(' brw-ph--lg-on brw-ph--lgm-on');

    // and a brand's own Off beats the shop's On
    Brand::query()->where('slug', 'anuabr4')->first()->forceFill(['header_layout' => ['logo_show' => 'off', 'logo_show_m' => 'off']])->save();
    expect(br4Panel(br4Page()))->not->toContain('brw-logo');

    // On one device only: printed, and hidden on the other by the stylesheet.
    [$laptop, $phone] = br4CssDevices();
    expect($laptop)->toContain('.brw-ph:not(.brw-ph--lg-on) .brw-ph__id>.brw-logo{display:none}')
        ->and($phone)->toContain('.brw-ph:not(.brw-ph--lgm-on) .brw-ph__id>.brw-logo{display:none}')
        // the capsule closes up round the name alone
        ->and($phone)->toContain('.brw-ph:not(.brw-ph--lgm-on) .brw-ph__id{padding:8px 18px}');
});

/* ========================================================== alignment */

it('centres the name and the description by default, and prints a class only off centre, laptop and phone apart', function () {
    br4Brand(['banner' => br4Banner()]);
    br4Brand(['name' => 'Leftbr4', 'slug' => 'leftbr4', 'header_layout' => ['name_align' => 'left', 'desc_align' => 'right', 'desc_align_m' => 'left', 'pill_at' => 'top-left']]);

    expect(SiteLayout::SCHEMA['brand_name_align'][2])->toBe('center')
        ->and(SiteLayout::SCHEMA['brand_desc_align'][2])->toBe('center')
        ->and(SiteLayout::SCHEMA['brand_desc_align_m'][2])->toBe('center')
        ->and(SiteLayout::SCHEMA['brand_pill_at'][2])->toBe('bottom-center')
        ->and(br4Section(br4Page()))->not->toMatch('#brw-ph--(na|da|dam|at)-#')
        // MUTATION: drop an alignment from QUIET_CHOICES and its class is never printed.
        ->and(br4Section(br4Page('leftbr4')))->toContain(' brw-ph--at-top-left brw-ph--na-left brw-ph--da-right brw-ph--dam-left');

    br4Save(['brand_name_align' => 'right', 'brand_desc_align_m' => 'right']);
    expect(br4Section(br4Page()))->toContain(' brw-ph--na-right brw-ph--dam-right');

    // A word that is not an option is refused on the shop's screen too.
    $r = app(SiteLayout::class)->save(['brand_desc_align' => 'middle']);
    expect($r['rejected'])->toHaveKey('brand_desc_align');

    $css = br4Css();
    [$laptop, $phone] = br4CssDevices();
    expect($css)->toContain('.brw-ph__id{display:flex;align-items:center;justify-content:center;')
        ->and($css)->toMatch('#\.brw-ph__desc\{[^}]*text-align:center\}#')
        ->and($laptop)->toContain('.brw-ph--na-left .brw-ph__id{justify-content:flex-start;text-align:start}')
        ->and($laptop)->toContain('.brw-ph--na-right .brw-ph__id{justify-content:flex-end;text-align:end}')
        ->and($laptop)->toContain('.brw-ph--da-left .brw-ph__desc{text-align:start}')
        ->and($laptop)->toContain('.brw-ph--da-right .brw-ph__desc{text-align:end}')
        ->and($phone)->toContain('.brw-ph--dam-left .brw-ph__desc{text-align:start}')
        ->and($phone)->toContain('.brw-ph--dam-right .brw-ph__desc{text-align:end}')
        // the phone capsule sits centred unless placed
        ->and($phone)->toContain('justify-self:center;position:relative')
        ->and($phone)->toContain('.brw-ph--at-bottom-left .brw-ph__id,.brw-ph--at-top-left .brw-ph__id{justify-self:start;');
});

/* ========================================================== read more */

it('cuts a long description at two lines with a real Read more button, and prints a short one whole with none', function () {
    br4Brand(['banner' => br4Banner(), 'description' => BR4_LONG]);
    br4Brand(['name' => 'Shortbr4', 'slug' => 'shortbr4', 'description' => 'Short and sweet.']);
    // ~100 characters: two lines on a laptop, three on a phone.
    br4Brand(['name' => 'Midbr4', 'slug' => 'midbr4', 'description' => str_repeat('Calm skin daily. ', 6)]);

    $long = br4Panel(br4Page());

    // MUTATION: print the button whatever the length and "Short" grows one.
    expect(strlen(BR4_LONG))->toBeGreaterThan(2 * BrandPanel::LINE_CHARS['laptop'])
        ->and(br4Section(br4Page()))->toContain(' brw-ph--more-l brw-ph--more-p"')
        ->and($long)->toContain('<div class="brw-ph__desc brw-desc"><div class="brw-ph__clamp" id="brw-ph-text">Anuabr4 believes')
        ->and($long)->toContain('<button class="brw-ph__more" type="button" aria-expanded="false" aria-controls="brw-ph-text" data-brw-more>'
            .'<span class="brw-ph__more-o">Read more</span><span class="brw-ph__more-c">Read less</span></button>')
        // once in the page's body (the <head> carries it as the meta description)
        ->and(substr_count(substr(br4Page(), (int) strpos(br4Page(), '<body')), 'Anuabr4 believes that healthy skin'))->toBe(1)
        ->and(br4Panel(br4Page('shortbr4')))->not->toContain('brw-ph__more')
        ->and(br4Panel(br4Page('shortbr4')))->not->toContain('brw-ph__clamp')
        ->and(br4Section(br4Page('shortbr4')))->not->toContain('brw-ph--more')
        ->and(br4Section(br4Page('midbr4')))->toContain(' brw-ph--more-p"')
        ->and(br4Section(br4Page('midbr4')))->not->toContain('brw-ph--more-l');

    // More lines before the cut: the long text fits four on a laptop.
    br4Save(['brand_desc_lines' => 4]);
    expect(br4Section(br4Page()))->not->toContain('brw-ph--more-l')
        ->and(br4Section(br4Page()))->toContain('--brw-ph-dl:4');

    // The cut and the button are CSS, per device; one label shows, by state.
    [$laptop, $phone] = br4CssDevices();
    expect($laptop)->toContain('.brw-ph--more-l .brw-ph__clamp:not(.is-open){display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:var(--brw-ph-dl,2);')
        ->and($laptop)->toContain('.brw-ph--more-l .brw-ph__more{display:inline-block}')
        ->and($phone)->toContain('.brw-ph--more-p .brw-ph__clamp:not(.is-open){display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:var(--brw-ph-dlm,2);')
        ->and($phone)->toContain('.brw-ph--more-p .brw-ph__more{display:inline-block}')
        ->and(br4Css())->toContain('.brw-ph__more[aria-expanded="true"] .brw-ph__more-o,.brw-ph__more[aria-expanded="false"] .brw-ph__more-c{display:none}')
        ->and(br4Css())->toContain('.brw-ph__more:focus-visible{')
        // nothing animates, so there is nothing for reduced motion to stop
        ->and(br4Css())->not->toMatch('#transition|animation#');
});

it('opens and closes in place with one attribute and one class -- nothing measured', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/tabs.js'));
    $start = (int) strpos($js, "const panel = document.querySelector('[data-kbb-brand-header]');");
    $handler = substr($js, $start, (int) strpos($js, "\n}\n", $start) - $start);

    expect($start)->toBeGreaterThan(0)
        ->and($handler)->toContain("event.target.closest('[data-brw-more]')")
        ->and($handler)->toContain("btn.setAttribute('aria-expanded', open ? 'true' : 'false');")
        ->and($handler)->toContain("text.classList.toggle('is-open', open);")
        // MUTATION: measure the text to decide anything and this is red.
        ->and($handler)->not->toMatch('#getBoundingClientRect|offsetHeight|scrollHeight|clientHeight|getComputedStyle#');
});

it('says Read more / Read less in both languages', function () {
    $en = \App\Services\Translation\InterfaceStrings::flat();
    $ar = \App\Services\Translation\ArabicInterfaceDrafts::all();

    expect($en['store.brands.read_more'] ?? null)->toBe('Read more')
        ->and($en['store.brands.read_less'] ?? null)->toBe('Read less')
        ->and($ar['store.brands.read_more'] ?? null)->toBe('اقرأ المزيد')
        ->and($ar['store.brands.read_less'] ?? null)->toBe('اقرأ أقل');
});

/* ========================================================== migration */

it('moves a stored Compact or Classic header to the Panel he asked for, and the old phone default to centre', function () {
    $migration = require database_path('migrations/2027_08_27_100000_brand_panel_on_every_brand_page.php');

    foreach ([['compact', 'panel'], ['classic', 'panel'], ['panel', 'panel']] as [$stored, $want]) {
        Setting::query()->updateOrCreate(['key' => 'layout_brand_hero'], ['value' => $stored]);
        Setting::query()->updateOrCreate(['key' => 'layout_brand_pill_at'], ['value' => 'bottom-left']);
        $migration->up();

        expect(DB::table('settings')->where('key', 'layout_brand_hero')->value('value'))->toBe($want, "stored {$stored}")
            ->and(DB::table('settings')->where('key', 'layout_brand_pill_at')->value('value'))->toBe('bottom-center');
    }

    // A position picked on purpose stays where it was put.
    Setting::query()->updateOrCreate(['key' => 'layout_brand_pill_at'], ['value' => 'top-right']);
    $migration->up();
    expect(DB::table('settings')->where('key', 'layout_brand_pill_at')->value('value'))->toBe('top-right');

    // And the shop reads Panel at once.
    Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
    br4Brand(['banner' => br4Banner()]);
    expect(br4Section(br4Page()))->not->toBe('');
});

/* ============================================================== pop-up */

describe('the "Edit brand header" pop-up on a brand with a page banner', function () {
    beforeEach(function () {
        StorefrontAdminRoutes::wire($this->app);
        $this->actingAs(AdminUser::create([
            'name' => 'BR4 Owner', 'email' => 'br4-qe@example.test', 'password' => 'password-long-enough', 'role' => 'owner',
        ]), 'admin');
    });

    it('offers the Panel\'s controls and previews the Panel, not the banner', function () {
        $brand = br4Brand(['banner' => br4Banner(), 'description' => BR4_LONG]);

        $ctx = $this->getJson('/admin-api/storefront/context?path='.rawurlencode('/brands/anuabr4/'))->assertOk();

        expect($ctx->json('edit.panel.on'))->toBeTrue()
            ->and($ctx->json('edit.mode'))->toBe('banner')
            ->and($ctx->json('edit.panel.shop'))->toMatchArray(['logo_show' => 'off', 'logo_show_m' => 'off', 'name_align' => 'center',
                'desc_align' => 'center', 'desc_align_m' => 'center', 'pill_at' => 'bottom-center', 'lines' => 2, 'lines_m' => 2])
            ->and($ctx->json('edit.panel.ranges.lines'))->toBe(['min' => 1, 'max' => 6, 'unit' => ''])
            ->and($ctx->json('edit.panel.ranges.lines_m'))->toBe(['min' => 1, 'max' => 6, 'unit' => '']);

        $layout = ['logo_show' => 'on', 'name_align' => 'left', 'desc_align_m' => 'right', 'lines' => 3];
        $pv = $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}/preview", ['layout' => $layout, 'path' => '/brands/anuabr4/'])->assertOk();

        expect($pv->json('kind'))->toBe('panel')
            ->and($pv->json('html'))->toContain('src="/uploads/brands/anua-green.webp"')
            ->and($pv->json('html'))->toContain(' brw-ph--lg-on brw-ph--na-left brw-ph--dam-right')
            ->and($pv->json('html'))->toContain('--brw-ph-dl:3')
            ->and($pv->json('html'))->toContain('data-brw-more');

        // The description is the pop-up's to change on a banner brand, under the Panel.
        $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", ['layout' => $layout, 'header_description' => 'Typed on the page.', 'path' => '/brands/anuabr4/'])->assertOk();
        expect($brand->fresh()->header_layout)->toEqual($layout)
            ->and(br4Panel(br4Page()))->toContain('Typed on the page.');
    });

    it('refuses a value that is not one of its options or out of range', function () {
        $brand = br4Brand(['banner' => br4Banner()]);

        foreach ([['logo_show' => 'yes'], ['name_align' => 'middle'], ['desc_align_m' => 'justify'], ['lines' => 0], ['lines_m' => 7], ['lines' => '2;x']] as $bad) {
            $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", ['layout' => $bad, 'path' => '/brands/anuabr4/'])->assertStatus(422);
        }

        expect($brand->fresh()->header_layout)->toBeNull();
    });

    it('has every new control in the pop-up script, on its own device', function () {
        $js = (string) file_get_contents(resource_path('js/kbb/admin/storefront-admin.js'));

        expect($js)->toContain("['logo_show', 'Show the brand logo', 'brw-ph--lg-', [['off', 'Off'], ['on', 'On']], 'laptop', true]")
            ->and($js)->toContain("['logo_show_m', 'Show the brand logo', 'brw-ph--lgm-', [['off', 'Off'], ['on', 'On']], 'phone', true]")
            ->and($js)->toContain("['name_align', 'Brand name alignment', 'brw-ph--na-'")
            ->and($js)->toContain("['desc_align', 'Description alignment', 'brw-ph--da-'")
            ->and($js)->toContain("['desc_align_m', 'Description alignment', 'brw-ph--dam-'")
            ->and($js)->toContain("['pill_at', 'Name position (with the logo, when it shows)'")
            ->and($js)->toContain("['lines', 'Description lines before \"Read more\"', '--brw-ph-dl', 'laptop']")
            ->and($js)->toContain("['lines_m', 'Description lines before \"Read more\"', '--brw-ph-dlm', 'phone']")
            // a banner brand under the Panel still has its Description box
            ->and($js)->toContain("isBanner && !panel ? null : fld('kbb-qe-desc'");
    });
});

/* ============================================================= queries */

it('costs a banner brand nothing extra: flat from 1 product to 40, and the same as a brand without a banner', function () {
    $banner = br4Brand(['banner' => br4Banner(), 'description' => BR4_LONG, 'logo' => '/uploads/brands/l.png']);
    br4Brand(['name' => 'Plainbr4', 'slug' => 'plainbr4', 'header_image' => '/uploads/brands/x.webp', 'description' => BR4_LONG, 'logo' => '/uploads/brands/l.png']);

    $count = function (string $slug): int {
        $this->get("/brands/{$slug}/")->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get("/brands/{$slug}/")->assertOk();

        return count(DB::getQueryLog());
    };

    $one = $count('anuabr4');
    $plain = $count('plainbr4');

    for ($i = 1; $i <= 39; $i++) {
        Product::create(['slug' => "br4-more-{$i}", 'name' => "BR4 More {$i}", 'brand_id' => $banner->id,
            'price' => 1000 + $i, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple']);
    }

    expect($count('anuabr4'))->toBe($one)->and($one)->toBe($plain);
});
