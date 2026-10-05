<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Services\SiteLayout;
use App\Support\BrandPanel;
use Illuminate\Support\Facades\DB;
use Tests\Support\StorefrontAdminRoutes;

/*
 * THE BRAND PAGE'S PANEL HEADER.                                     (Lane BR2)
 *
 * The owner, with a screenshot of Anua: the banner carried the description in
 * grey text across the middle of the picture, where it merged with the photo,
 * and the logo sat alone under the banner. His words:
 *
 *   "logo will be on th background image, beside logo, brand name, and
 *    downside brand description. and put a nice background of the content to
 *    not merge with the background, and content should should not full width,
 *    almost 60% of the page width, and in mobile the description will come
 *    under banner, not on the banner."
 *
 *   "For BRAND page: we needed with the content background. and in mobile logo
 *    and name with capsule type or rectangle background. and content will come
 *    downside the header area."
 *
 * Plus: a circle or rectangle logo per brand, and drag bars for the header's
 * width and height per brand. He asked, so the Panel header ships ON
 * (Appearance → Site layout → Brand page → Brand header style); Compact and
 * Classic stay one pick away.
 *
 * MUTATIONS, each red here:
 *   · brand_hero's default back to 'compact'                     → "ships"
 *   · BrandPanel::sanitize() without the min()/max() clamp        → "clamps"
 *   · in_array() dropped from a choice in sanitize()              → "clamps"
 *   · the description printed in .brw-ph__id as well             → "once"
 *   · `@container` in kbb-brand-header.css turned into `@media`  → "phone shape"
 *   · 'layout' taken out of StorefrontAdminController::BRAND_KEYS → "quick edit"
 *   · BrandPanel::forBrand() loading the brand again             → "queries"
 */

function brpBrand(array $extra = []): Brand
{
    return Brand::create(array_merge([
        'name' => 'Anuabr', 'slug' => 'anuabr',
        'description' => 'Anuabr believes healthy skin comes from a relaxed mind.',
        'header_image' => '/uploads/brands/anua-banner.webp',
    ], $extra));
}

function brpProducts(Brand $brand, int $n, string $prefix = 'brp'): void
{
    for ($i = 1; $i <= $n; $i++) {
        Product::create([
            'slug' => "{$prefix}-{$i}", 'name' => "BRP Product {$i}", 'brand_id' => $brand->id,
            'price' => 1000 + $i, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
        ]);
    }
}

function brpSave(array $values): array
{
    $r = app(SiteLayout::class)->save($values);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();

    return $r;
}

/** The opening tag of the panel header's <section>. */
function brpSection(string $html): string
{
    return preg_match('#<section class="brw-ph[^"]*" style="[^"]*"[^>]*>#', $html, $m) === 1 ? $m[0] : '';
}

function brpCss(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-brand-header.css'));
}

/* ================================================================ the page */

it('ships the Panel header: banner behind, logo + name + description in a frosted panel 60% wide', function () {
    brpProducts(brpBrand(), 2);

    $html = (string) $this->get('/brands/anuabr/')->assertOk()->getContent();

    expect(SiteLayout::SCHEMA['brand_hero'][2])->toBe('panel')
        ->and(brpSection($html))->toBe('<section class="brw-ph brw-ph--frost brw-ph--pill-capsule brw-ph--logo-circle brw-ph--pos-center" style="--brw-ph-w:100%;--brw-ph-h:270px;--brw-ph-hm:165px;--brw-ph-cw:60%;--brw-ph-dk:#431a25;--brw-ph-lt:#fceef2" aria-labelledby="brw-ph-title">')
        ->and($html)->toContain('<img class="brw-ph__img" src="/uploads/brands/anua-banner.webp" alt=""')
        // the stylesheet, and not the title header's or the compact row
        ->and($html)->toMatch('#<link rel="stylesheet" href="[^"]*kbb-brand-header[^"]*\.css"#')
        ->and($html)->not->toContain('data-kbb-title-header')
        ->and($html)->not->toContain('<div class="brw-hero');

    // Inside the panel: the name, then the description. (Lane BR4: no logo
    // until "Show the brand logo" is on -- the owner's default.)
    expect(preg_match('#<div class="brw-ph__panel">\s*<div class="brw-ph__id">\s*<h1 class="brw-ph__name" id="brw-ph-title">Anuabr</h1>\s*</div>\s*<div class="brw-ph__desc brw-desc">Anuabr believes#s', $html))->toBe(1);
});

it('prints the heading and the description once each, as real text', function () {
    brpProducts(brpBrand(['header_description' => '<p>One <b>copy</b> only.</p>']), 1);

    $html = (string) $this->get('/brands/anuabr/')->assertOk()->getContent();
    $body = substr($html, (int) strpos($html, '<body'));

    // The phone puts the description under the banner by grid row, not by a
    // second copy. MUTATION: print it in .brw-ph__id too and this is 2.
    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(substr_count($body, 'One <b>copy</b> only.'))->toBe(1)
        ->and(substr_count($html, 'brw-desc'))->toBe(1 + substr_count($html, '.brw-desc'));
});

it('lays the phone out by the header\'s own width: pill on the banner, description below in a card', function () {
    $css = brpCss();

    preg_match('#@container \(max-width:599px\)\{(.*)\}\s*$#s', $css, $m);
    $phone = $m[1] ?? '';

    // MUTATION: make it `@media` and the quick editor's phone preview lies.
    expect($css)->toContain('.brw-phw{container-type:inline-size')
        ->and($phone)->toContain('.brw-ph__panel{display:contents}')
        ->and($phone)->toMatch('#\.brw-ph__id\{grid-area:1/1;align-self:end#')
        ->and($phone)->toMatch('#\.brw-ph__desc\{grid-area:2/1;#')
        ->and($phone)->toContain('border-radius:999px')
        ->and($phone)->toContain('.brw-ph--pill-rect .brw-ph__id{border-radius:12px}')
        ->and($phone)->toContain('.brw-ph__media{min-height:var(--brw-ph-hm,165px)')
        // the picture covers the header on a phone too (2.60.387): cover, never contain
        ->and($css)->toContain('object-fit:cover')
        ->and($css)->not->toContain('object-fit:contain')
        ->and($css)->not->toMatch('#@media#');

    // THE DEFECT, found in the preview: a SQUARE logo in the Rectangle shape
    // was drawn at the rectangle's width (166px) and cropped top and bottom --
    // "An" showing and "ua" cut off -- because the logo box's implicit grid
    // row took the picture's own height. MUTATION: drop the grid-template and
    // this is red.
    // (Lane BR3: the 72px is now the fallback of the logo-size property.)
    expect($css)->toContain('.brw-ph .brw-logo--lg{width:var(--brw-ph-lg,72px);height:var(--brw-ph-lg,72px);background:#fff;flex:none;grid-template:minmax(0,1fr)/minmax(0,1fr)}')
        ->and($css)->toContain('.brw-ph--logo-rect .brw-logo--lg{width:auto;aspect-ratio:2.3/1;border-radius:12px}');

    // The laptop panel: the content width and the frosted ground.
    // (Lane BR3: never wider than the banner less its two insets.)
    expect($css)->toContain('width:min(var(--brw-ph-cw,60%),calc(100% - 2 * var(--brw-ph-in,36px)))')
        // Lane BR3: design A's own .84 (it was .86).
        ->and($css)->toContain('.brw-ph--frost .brw-ph__panel{background:rgba(255,255,255,.84)')
        ->and($css)->toContain('.brw-ph--pos-left .brw-ph__img{object-position:left center}')
        ->and($css)->toContain('.brw-ph--pos-right .brw-ph__img{object-position:right center}');
});

it('takes each brand\'s own layout over the shop\'s, and the shop\'s over the shipped one', function () {
    brpProducts(brpBrand(['logo_color' => '#1f7a35', 'header_layout' => [
        'logo' => 'rect', 'panel' => 'brand', 'pill' => 'rect', 'position' => 'right',
        'width' => 80, 'height' => 320, 'height_m' => 200, 'content' => 50,
    ]]), 1);
    brpProducts(Brand::create(['name' => 'Plainbr', 'slug' => 'plainbr']), 1, 'plain');

    brpSave(['brand_panel_style' => 'brand', 'brand_banner_h' => 300, 'brand_img_pos' => 'left']);

    $own = brpSection((string) $this->get('/brands/anuabr/')->getContent());
    $shop = brpSection((string) $this->get('/brands/plainbr/')->getContent());

    expect($own)->toContain('class="brw-ph brw-ph--brand brw-ph--pill-rect brw-ph--logo-rect brw-ph--pos-right"')
        ->and($own)->toContain('--brw-ph-w:80%;--brw-ph-h:320px;--brw-ph-hm:200px;--brw-ph-cw:50%;')
        // a dark shade and a pale tint of the brand's own green
        ->and($own)->toContain('--brw-ph-dk:'.BrandPanel::mix('#1f7a35', '#000000', 30))
        ->and($own)->toContain('--brw-ph-lt:'.BrandPanel::mix('#1f7a35', '#ffffff', 10))
        ->and($shop)->toContain('class="brw-ph brw-ph--brand brw-ph--pill-capsule brw-ph--logo-circle brw-ph--pos-left brw-ph--noimg"')
        ->and($shop)->toContain('--brw-ph-h:300px');
});

it('clamps every size and refuses every word it does not know, from the brand and from the shop', function () {
    brpProducts(brpBrand(['logo_color' => 'url(x)', 'header_layout' => [
        'width' => '80;background:url(x)', 'height' => 9999, 'height_m' => -5, 'content' => 12.9,
        'panel' => 'brand;color:red', 'logo' => '<script>', 'pill' => ['capsule'], 'position' => 'top', 'evil' => 'x',
    ]]), 1);

    expect(BrandPanel::sanitize(['width' => '80;x', 'height' => 9999, 'height_m' => -5, 'content' => 12.9, 'panel' => 'brand;x', 'evil' => 1]))
        ->toBe(['height' => 460, 'height_m' => 100, 'content' => 40]);

    $section = brpSection((string) $this->get('/brands/anuabr/')->getContent());

    expect($section)->toContain('class="brw-ph brw-ph--frost brw-ph--pill-capsule brw-ph--logo-circle brw-ph--pos-center"')
        ->and($section)->toContain('--brw-ph-w:100%;--brw-ph-h:460px;--brw-ph-hm:100px;--brw-ph-cw:40%;')
        // no colour of its own that is a colour: the shop pink's shades
        ->and($section)->toContain('--brw-ph-dk:#431a25')
        ->and($section)->not->toContain('url(')
        ->and($section)->not->toContain('script');

    // The shop's bars are clamped by the screen that saves them, and its
    // selects store one of their own options or nothing.
    $r = brpSave(['brand_header_w' => 400, 'brand_content_w' => 1, 'brand_pill' => 'oval', 'brand_panel_style' => 'x;y']);
    expect(app(SiteLayout::class)->get('brand_header_w'))->toBe(100)
        ->and(app(SiteLayout::class)->get('brand_content_w'))->toBe(40)
        ->and($r['rejected'])->toHaveKeys(['brand_pill', 'brand_panel_style']);
});

it('draws a brand with no banner on its own colour, with no picture and no broken image', function () {
    brpProducts(brpBrand(['header_image' => null, 'logo_color' => '#1f7a35']), 1);
    brpProducts(Brand::create(['name' => 'Jsbr', 'slug' => 'jsbr', 'header_image' => 'javascript:alert(1)']), 1, 'js');

    $html = (string) $this->get('/brands/anuabr/')->getContent();
    $js = (string) $this->get('/brands/jsbr/')->getContent();

    expect(brpSection($html))->toContain('brw-ph--noimg')
        ->and($html)->not->toContain('brw-ph__img')
        ->and(brpSection($js))->toContain('brw-ph--noimg')
        ->and($js)->not->toContain('javascript:alert');
});

it('draws the Panel for a brand with the owner\'s own page banner too, its picture as the background (Lane BR4)', function () {
    // This used to pin the opposite -- "keeps the compact row under it" --
    // which is exactly what kept the live Anua off the Panel. BrandPanelEveryBrandTest
    // carries the owner's report and the rest.
    $brand = brpBrand(['banner' => ['enabled' => true, 'image' => '/uploads/brands/own.jpg', 'heading' => 'Own']]);
    brpProducts($brand, 1);

    expect(\App\Http\Controllers\Store\BrandController::hero('panel'))->toBe('panel')
        ->and(\App\Http\Controllers\Store\BrandController::hero('classic'))->toBe('classic')
        ->and(\App\Http\Controllers\Store\BrandController::hero('nonsense'))->toBe('compact');

    expect(\App\Support\PageBanner::forModel($brand->fresh(), 'Anuabr'))->not->toBeNull();
    $html = (string) $this->get('/brands/anuabr/')->getContent();
    expect($html)->toContain('<img class="brw-ph__img" src="/uploads/brands/own.jpg"')
        ->and($html)->not->toContain('<div class="brw-hero')
        ->and($html)->not->toContain('<section class="kbb-banner');
});

it('escapes the brand name, and Compact and Classic still draw exactly their own headers', function () {
    brpProducts(brpBrand(['name' => 'An<b>ua</b> & Co']), 1);

    $html = (string) $this->get('/brands/anuabr/')->getContent();
    expect($html)->toContain('<h1 class="brw-ph__name" id="brw-ph-title">An&lt;b&gt;ua&lt;/b&gt; &amp; Co</h1>');

    brpSave(['brand_hero' => 'compact']);
    $compact = (string) $this->get('/brands/anuabr/')->getContent();
    brpSave(['brand_hero' => 'classic']);
    $classic = (string) $this->get('/brands/anuabr/')->getContent();

    expect($compact)->toContain('brw-hero--compact')->and($compact)->not->toContain('brw-ph')
        ->and($compact)->not->toContain('kbb-brand-header')
        ->and($classic)->toContain('<div class="brw-hero">')->and($classic)->not->toContain('brw-ph');
});

/* ================================================================ queries */

it('costs the brand page no query: flat from 3 products to 40, and the same as Compact', function () {
    /*
     * Rule 4. Everything the panel prints comes off the brand row the page
     * already loads and the settings map. MUTATION: have BrandPanel::forBrand()
     * call $brand->fresh() and the panel count goes up by one against Compact.
     */
    $brand = brpBrand(['logo_color' => '#1f7a35', 'header_layout' => ['logo' => 'rect', 'height' => 300]]);
    brpProducts($brand, 3);
    $this->get('/brands/anuabr/')->assertOk();

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('/brands/anuabr/')->assertOk();
    $three = count(DB::getQueryLog());

    brpProducts($brand, 37, 'brp-more');
    $this->get('/brands/anuabr/')->assertOk();
    DB::flushQueryLog();
    $this->get('/brands/anuabr/')->assertOk();
    $forty = count(DB::getQueryLog());

    brpSave(['brand_hero' => 'compact']);
    $this->get('/brands/anuabr/')->assertOk();
    DB::flushQueryLog();
    $this->get('/brands/anuabr/')->assertOk();
    $compact = count(DB::getQueryLog());

    expect($forty)->toBe($three)->and($forty)->toBe($compact);
});

/* ============================================================ quick edit */

describe('the "Edit brand header" pop-up', function () {
    beforeEach(function () {
        StorefrontAdminRoutes::wire($this->app);
        $this->actingAs(AdminUser::create([
            'name' => 'BR2 Owner', 'email' => 'br2-qe@example.test', 'password' => 'password-long-enough', 'role' => 'owner',
        ]), 'admin');
    });

    it('offers the layout with the shop\'s values and ranges, and the Brand page link', function () {
        brpBrand(['header_layout' => ['logo' => 'rect']]);
        brpSave(['brand_banner_h' => 300]);

        $ctx = $this->getJson('/admin-api/storefront/context?path='.rawurlencode('/brands/anuabr/'))->assertOk();

        expect($ctx->json('edit.keys'))->toContain('layout')
            ->and($ctx->json('edit.fields.layout'))->toBe(['logo' => 'rect'])
            ->and($ctx->json('edit.panel.on'))->toBeTrue()
            ->and($ctx->json('edit.panel.shop'))->toBe(['logo' => 'circle', 'panel' => 'frost', 'pill' => 'capsule', 'position' => 'center',
                'panel_x' => 'left', 'panel_y' => 'middle', 'pill_at' => 'bottom-center',
                // Lane BR4: the logo off, the name and the description centred.
                'logo_show' => 'off', 'logo_show_m' => 'off', 'name_align' => 'center', 'desc_align' => 'center', 'desc_align_m' => 'center',
                'width' => 100, 'height' => 300, 'height_m' => 165, 'content' => 60,
                // Lane BR3's sizes, at design A's values.
                'pad' => 26, 'inset' => 36, 'gap' => 12, 'name' => 34, 'desc' => 15, 'logo_size' => 72,
                'inset_m' => 12, 'gap_m' => 12, 'card_pad_m' => 14, 'name_m' => 22, 'desc_m' => 14, 'logo_size_m' => 52,
                'lines' => 2, 'lines_m' => 2])
            ->and($ctx->json('edit.panel.hint'))->toContain('Appearance → Site layout → Brand page')
            ->and($ctx->json('edit.panel.ranges.width'))->toBe(['min' => 60, 'max' => 100, 'unit' => '%'])
            ->and($ctx->json('edit.panel.ranges.height_m'))->toBe(['min' => 100, 'max' => 300, 'unit' => 'px'])
            ->and($ctx->json('edit.css.panel'))->toContain('kbb-brand-header')
            ->and(collect($ctx->json('edit.more'))->pluck('label')->all())->toContain('Appearance → Site layout → Brand page');
    });

    it('saves the brand\'s layout, previews it unsaved, and hands back the header the page draws', function () {
        $brand = brpBrand();

        $layout = ['logo' => 'rect', 'panel' => 'brand', 'pill' => 'rect', 'position' => 'left', 'width' => 90, 'height' => 220, 'height_m' => 140, 'content' => 70];

        $pv = $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}/preview", ['layout' => $layout, 'path' => '/brands/anuabr/'])->assertOk();
        expect($pv->json('kind'))->toBe('panel')
            ->and($pv->json('html'))->toContain('class="brw-ph brw-ph--brand brw-ph--pill-rect brw-ph--logo-rect brw-ph--pos-left"')
            ->and($pv->json('html'))->toContain('data-kbb-brand-header')
            ->and($brand->fresh()->header_layout)->toBeNull();

        $r = $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", ['layout' => $layout, 'path' => '/brands/anuabr/'])->assertOk();

        expect($brand->fresh()->header_layout)->toEqual($layout)
            ->and($r->json('kind'))->toBe('panel')
            ->and($r->json('html'))->toContain('--brw-ph-w:90%;--brw-ph-h:220px;--brw-ph-hm:140px;--brw-ph-cw:70%;')
            ->and($r->json('fields.layout'))->toEqual($layout);

        // An empty layout puts the brand back on the shop's settings.
        $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", ['layout' => [], 'path' => '/brands/anuabr/'])->assertOk();
        expect($brand->fresh()->header_layout)->toBeNull();
    });

    it('refuses a size out of range, a word that is not an option, and a key it does not own', function () {
        $brand = brpBrand(['header_layout' => ['height' => 300]]);

        foreach ([['width' => 59], ['width' => 101], ['content' => 86], ['height' => 'tall'], ['panel' => 'red'], ['pill' => 'oval'], ['position' => 'top'], ['evil' => 1]] as $bad) {
            $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", ['layout' => $bad, 'path' => '/brands/anuabr/'])
                ->assertStatus(422);
        }

        expect($brand->fresh()->header_layout)->toBe(['height' => 300]);
    });
});
