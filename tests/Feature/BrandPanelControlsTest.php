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
 * THE BRAND PANEL HEADER'S BOX, POSITION AND TYPE CONTROLS.          (Lane BR3)
 *
 * The owner, choosing design A (the Frosted glass panel) of
 * docs/brand-header-preview:
 *
 *   "for brand i need this as you proposed. with logo circle or rectangle to
 *    choose from, and all controls options like box spacings, positioning etc
 *    and to adjust the height of overal header also, and font sizes etc. ...
 *    allow controls for desktop and mobile both on the front-end."
 *
 * Fifteen controls, each per brand ("Edit brand header" pop-up, Header layout)
 * with the shop's value on Appearance → Site layout → Brand page:
 *   laptop  panel across / up-down, panel padding, inset, name-to-text gap,
 *           name size, description size, logo size
 *   phone   capsule position, capsule inset, gap above the card, card padding,
 *           name size, description size, logo size
 *
 * A size at its default prints NOTHING (BrandPanel::QUIET) and a position at
 * its first option prints no class, so a brand page nobody touched keeps BR2's
 * markup byte for byte.
 *
 * MUTATIONS, each red here:
 *   · BrandPanel::forBrand() printing every size, QUIET skip dropped    → "untouched"
 *   · a QUIET value, a SCHEMA default or a CSS fallback moved alone     → "one number"
 *   · the phone logo / name fallback back to BR2's 44px / 20px          → "design A"
 *   · the min()/max() clamp dropped from sanitize()                     → "clamps"
 *   · a new key missing from PANEL_BARS / PANEL_CHOICES in the pop-up   → "pop-up"
 *   · a phone property moved out of the @container block                → "phone"
 *   · .brw-ph--at-top-left's rule deleted                               → "positions"
 *   · forBrand() loading the brand again                                → "queries"
 */

const BR3_LAPTOP = ['pad', 'inset', 'gap', 'name', 'desc', 'logo_size'];
const BR3_PHONE = ['inset_m', 'gap_m', 'card_pad_m', 'name_m', 'desc_m', 'logo_size_m'];

function br3Brand(array $extra = []): Brand
{
    return Brand::create(array_merge([
        'name' => 'Anuabr3', 'slug' => 'anuabr3',
        'description' => 'Anuabr3 believes healthy skin comes from a relaxed mind.',
        'header_image' => '/uploads/brands/anua-banner.webp',
    ], $extra));
}

function br3Products(Brand $brand, int $n, string $prefix = 'br3'): void
{
    for ($i = 1; $i <= $n; $i++) {
        Product::create([
            'slug' => "{$prefix}-{$i}", 'name' => "BR3 Product {$i}", 'brand_id' => $brand->id,
            'price' => 1000 + $i, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
        ]);
    }
}

function br3Save(array $values): array
{
    $r = app(SiteLayout::class)->save($values);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();

    return $r;
}

function br3Section(string $html): string
{
    return preg_match('#<section class="brw-ph[^"]*" style="[^"]*"[^>]*>#', $html, $m) === 1 ? $m[0] : '';
}

function br3Css(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-brand-header.css'));
}

/** [laptop rules, phone rules] of the stylesheet, split at its one @container. */
function br3CssParts(): array
{
    $css = br3Css();
    $at = strpos($css, '@container (max-width:599px){');

    return [substr($css, 0, (int) $at), substr($css, (int) $at)];
}

/** A layout that moves every BR3 control off its default. */
function br3Everything(): array
{
    return [
        'panel_x' => 'right', 'panel_y' => 'bottom', 'pill_at' => 'top-center',
        'pad' => 40, 'inset' => 60, 'gap' => 20, 'name' => 44, 'desc' => 18, 'logo_size' => 96,
        'inset_m' => 20, 'gap_m' => 24, 'card_pad_m' => 20, 'name_m' => 26, 'desc_m' => 16, 'logo_size_m' => 64,
    ];
}

/* ============================================================= defaults */

it('keeps an untouched brand page byte for byte as BR2 drew it: no new property, no new class', function () {
    br3Products(br3Brand(), 1);

    $section = br3Section((string) $this->get('/brands/anuabr3/')->assertOk()->getContent());

    // MUTATION: print every size whatever its value and this grows twelve
    // properties; print the first option's class and it grows three classes.
    expect($section)->toBe('<section class="brw-ph brw-ph--frost brw-ph--pill-capsule brw-ph--logo-circle brw-ph--pos-center" style="--brw-ph-w:100%;--brw-ph-h:270px;--brw-ph-hm:165px;--brw-ph-cw:60%;--brw-ph-dk:#431a25;--brw-ph-lt:#fceef2" aria-labelledby="brw-ph-title">');
});

it('holds each default to one number: BrandPanel::QUIET, the shop setting and the stylesheet fallback', function () {
    // Lane BR4 adds the lines before "Read more", laptop and phone: a count, not px.
    // 6 Oct: the header's outer spacing, laptop then phone.
    expect(array_keys(BrandPanel::QUIET))->toBe([...BR3_LAPTOP, ...BR3_PHONE, 'lines', 'lines_m', 'space_top', 'space_x', 'space_top_m', 'space_x_m']);

    [$laptop, $phone] = br3CssParts();

    foreach (BrandPanel::QUIET as $key => $default) {
        [$setting, $min, $max, $property, $unit] = BrandPanel::RANGES[$key];
        $field = SiteLayout::SCHEMA[$setting];

        // MUTATION: move any one of the three and this names the one that moved.
        expect($field[0])->toBe('range', $setting)
            ->and($field[2])->toBe($default, "SCHEMA default of {$setting}")
            ->and($field[4]['min'])->toBe($min, "{$setting} min")
            ->and($field[4]['max'])->toBe($max, "{$setting} max")
            ->and($default)->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max)
            ->and($unit)->toBe(in_array($key, ['lines', 'lines_m'], true) ? '' : 'px');

        $scope = in_array($key, [...BR3_PHONE, 'lines_m', 'space_top_m', 'space_x_m'], true) ? $phone : $laptop;
        $other = in_array($key, [...BR3_PHONE, 'lines_m', 'space_top_m', 'space_x_m'], true) ? $laptop : $phone;

        // Every fallback of this property, in the half of the sheet it belongs
        // to, is the default -- and the property is not read in the other half.
        preg_match_all('#var\('.preg_quote($property, '#').',([^)(]+(?:\([^)]*\))?[^)]*)\)#', $scope, $m);
        expect($m[1])->not->toBeEmpty("{$property} is read by nothing in its half of the sheet");

        foreach ($m[1] as $fallback) {
            expect(in_array($fallback, [$default.$unit, "clamp(24px,3cqi,{$default}px)", "clamp(16px,3cqi,{$default}px)"], true))
                ->toBeTrue("{$property} falls back to {$fallback}, not {$default}px");
        }

        expect(preg_match('#var\('.preg_quote($property, '#').'[,)]#', $other))->toBe(0, "{$property} leaks into the other device");
    }

    foreach (BrandPanel::QUIET_CHOICES as $key => $prefix) {
        [$setting, $options] = BrandPanel::CHOICES[$key];

        // Lane BR4: "Show the brand logo" is a switch on Site layout; Off is
        // its default and the first option, so it too prints nothing.
        if (in_array($key, BrandPanel::SWITCHES, true)) {
            expect(SiteLayout::SCHEMA[$setting][0])->toBe('bool')
                ->and(SiteLayout::SCHEMA[$setting][2])->toBeFalse()
                ->and($options)->toBe(['off', 'on']);

            continue;
        }

        expect(SiteLayout::SCHEMA[$setting][0])->toBe('select')
            ->and(SiteLayout::SCHEMA[$setting][2])->toBe($options[0], "{$setting} ships at the stylesheet's own layout")
            ->and(array_keys(SiteLayout::SCHEMA[$setting][4]))->toBe($options);
    }
});

it('ships design A: a 52px logo and a 22px name in the phone capsule, a 36px inset and the .84 frost on a laptop', function () {
    [$laptop, $phone] = br3CssParts();

    // BR2 drew the phone at 44px and 20px; the preview's design A, which the
    // owner picked, is 52px and 22px (.bh .logo / .bh h3 under its @container).
    // MUTATION: put 44px / 20px back and this is red.
    expect($phone)->toContain('.brw-ph .brw-logo--lg{width:var(--brw-ph-lgm,52px);height:var(--brw-ph-lgm,52px)}')
        ->and($phone)->toContain('.brw-ph__name{font-size:var(--brw-ph-fnm,22px)}')
        ->and($phone)->toContain('.brw-ph--frost .brw-ph__id{background:rgba(255,255,255,.88)')
        ->and($laptop)->toContain('margin-inline:var(--brw-ph-in,clamp(16px,3cqi,36px))')
        ->and($laptop)->toContain('.brw-ph--frost .brw-ph__panel{background:rgba(255,255,255,.84)')
        // and the logo stays a circle by default, the panel frosted, on the left, in the middle
        ->and(SiteLayout::SCHEMA['brand_logo_shape'][2])->toBe('circle')
        ->and(SiteLayout::SCHEMA['brand_panel_style'][2])->toBe('frost')
        ->and(SiteLayout::SCHEMA['brand_panel_x'][2])->toBe('left')
        ->and(SiteLayout::SCHEMA['brand_panel_y'][2])->toBe('middle');

    $preview = (string) file_get_contents(base_path('docs/brand-header-preview/index.html'));
    expect($preview)->toContain('.logo{width:52px;height:52px}')
        ->and($preview)->toContain('.bh h3{font-size:22px}')
        ->and($preview)->toContain('.bh-a .bh-panel{margin-inline-start:36px;background:rgba(255,255,255,.84)');
});

it('says which height is which: the laptop height is the whole header, the phone one is the banner above the card', function () {
    expect(SiteLayout::SCHEMA['brand_banner_h'][1])->toBe('Panel · laptop · header height')
        ->and(SiteLayout::SCHEMA['brand_banner_h'][3])->toContain('WHOLE header')
        ->and(SiteLayout::SCHEMA['brand_banner_h_m'][1])->toBe('Panel · phone · banner height')
        ->and(SiteLayout::SCHEMA['brand_banner_h_m'][3])->toContain('The description card comes BELOW it');

    // On a laptop the panel shares the banner's grid cell, so the banner's
    // min-height IS the header's height; on a phone the card takes row 2.
    [$laptop, $phone] = br3CssParts();
    expect($laptop)->toContain('.brw-ph__media{grid-area:1/1;position:relative;min-height:var(--brw-ph-h,270px)')
        ->and($laptop)->toContain('.brw-ph__panel{grid-area:1/1;')
        ->and($phone)->toContain('.brw-ph__desc{grid-area:2/1;');
});

/* =============================================================== render */

it('draws each brand\'s own box, position and type over the shop\'s', function () {
    br3Products(br3Brand(['header_layout' => br3Everything()]), 1);
    br3Products(Brand::create(['name' => 'Plainbr3', 'slug' => 'plainbr3']), 1, 'plain3');

    br3Save(['brand_panel_x' => 'center', 'brand_pill_at' => 'bottom-right', 'brand_name_fs' => 40, 'brand_logo_size_m' => 40]);

    $own = br3Section((string) $this->get('/brands/anuabr3/')->getContent());
    $shop = br3Section((string) $this->get('/brands/plainbr3/')->getContent());

    expect($own)->toContain('class="brw-ph brw-ph--frost brw-ph--pill-capsule brw-ph--logo-circle brw-ph--pos-center brw-ph--px-right brw-ph--py-bottom brw-ph--at-top-center"')
        ->and($own)->toContain('--brw-ph-cw:60%;--brw-ph-pad:40px;--brw-ph-in:60px;--brw-ph-gap:20px;--brw-ph-fn:44px;--brw-ph-fd:18px;--brw-ph-lg:96px;'
            .'--brw-ph-im:20px;--brw-ph-gm:24px;--brw-ph-cp:20px;--brw-ph-fnm:26px;--brw-ph-fdm:16px;--brw-ph-lgm:64px;--brw-ph-dk:')
        // the shop's values reach a brand with none of its own, and only those
        ->and($shop)->toContain('brw-ph--px-center brw-ph--at-bottom-right"')
        ->and($shop)->not->toContain('brw-ph--py-')
        ->and($shop)->toContain('--brw-ph-cw:60%;--brw-ph-fn:40px;--brw-ph-lgm:40px;--brw-ph-dk:');
});

it('lays out every position it offers, on its own device', function () {
    [$laptop, $phone] = br3CssParts();

    // MUTATION: delete any one rule and its option silently does nothing.
    expect($laptop)->toContain('.brw-ph--px-center .brw-ph__panel{justify-self:center}')
        ->and($laptop)->toContain('.brw-ph--px-right .brw-ph__panel{justify-self:end}')
        ->and($laptop)->toContain('.brw-ph--py-top .brw-ph__panel{align-self:start}')
        ->and($laptop)->toContain('.brw-ph--py-bottom .brw-ph__panel{align-self:end}');

    foreach (BrandPanel::PILL_AT as $i => $at) {
        if ($i === 0) {
            // Lane BR4: Bottom centre is now first, the stylesheet's own place.
            expect($at)->toBe('bottom-center')->and(br3Css())->not->toContain('brw-ph--at-bottom-center');

            continue;
        }

        expect($phone)->toContain('.brw-ph--at-'.$at.' .brw-ph__id');
    }

    foreach (['center', 'right'] as $x) {
        expect(br3Css())->toContain('.brw-ph--px-'.$x);
    }

    // A wide panel plus a wide inset never pushes past the banner (the
    // 390px phone and 1280px laptop shots read scrollWidth = viewport).
    expect($laptop)->toContain('width:min(var(--brw-ph-cw,60%),calc(100% - 2 * var(--brw-ph-in,36px)))')
        ->and($phone)->toContain('max-width:calc(100% - 2 * var(--brw-ph-im,12px))');
});

/* =========================================================== validation */

it('clamps every new size and drops every word that is not one of its options', function () {
    $bad = [
        'pad' => 999, 'inset' => -4, 'gap' => '30;color:red', 'name' => 9, 'desc' => 40.7, 'logo_size' => '7',
        'inset_m' => 41, 'gap_m' => [1], 'card_pad_m' => 1, 'name_m' => 99, 'desc_m' => '13', 'logo_size_m' => 'big',
        'panel_x' => 'middle', 'panel_y' => 'left', 'pill_at' => 'bottom-left;x',
    ];

    // MUTATION: drop the min()/max() and these come back as typed.
    expect(BrandPanel::sanitize($bad))->toBe([
        'pad' => 60, 'inset' => 0, 'name' => 18, 'desc' => 22, 'logo_size' => 40,
        'inset_m' => 40, 'card_pad_m' => 6, 'name_m' => 36, 'desc_m' => 13,
    ]);

    br3Products(br3Brand(['header_layout' => $bad]), 1);
    $section = br3Section((string) $this->get('/brands/anuabr3/')->getContent());

    expect($section)->toContain('--brw-ph-pad:60px;--brw-ph-in:0px;--brw-ph-fn:18px;--brw-ph-fd:22px;--brw-ph-lg:40px;--brw-ph-im:40px;--brw-ph-cp:6px;--brw-ph-fnm:36px;--brw-ph-fdm:13px;')
        ->and($section)->not->toContain('color:red')
        ->and($section)->not->toContain('brw-ph--px-')
        ->and($section)->not->toContain('brw-ph--at-');

    // The shop's screen clamps its bars and keeps its selects to their options.
    $r = br3Save(['brand_panel_pad' => 500, 'brand_logo_size_m' => 2, 'brand_pill_at' => 'middle', 'brand_panel_y' => '<b>']);
    expect(app(SiteLayout::class)->get('brand_panel_pad'))->toBe(60)
        ->and(app(SiteLayout::class)->get('brand_logo_size_m'))->toBe(28)
        ->and($r['rejected'])->toHaveKeys(['brand_pill_at', 'brand_panel_y']);
});

/* ============================================================== queries */

it('costs the page nothing: flat from 3 products to 40 with every control moved, and the same as untouched', function () {
    $brand = br3Brand(['header_layout' => br3Everything()]);
    $plain = Brand::create(['name' => 'Plainbr3', 'slug' => 'plainbr3', 'header_image' => '/uploads/brands/x.webp']);
    br3Products($brand, 3);
    br3Products($plain, 3, 'plain3');

    $count = function (string $slug): int {
        $this->get("/brands/{$slug}/")->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get("/brands/{$slug}/")->assertOk();

        return count(DB::getQueryLog());
    };

    $three = $count('anuabr3');
    $untouched = $count('plainbr3');
    br3Products($brand, 37, 'br3-more');
    $forty = $count('anuabr3');

    // MUTATION: $brand->fresh() inside BrandPanel::forBrand() and this is +1.
    expect($forty)->toBe($three)->and($three)->toBe($untouched);
});

/* =============================================================== pop-up */

describe('the "Edit brand header" pop-up', function () {
    beforeEach(function () {
        StorefrontAdminRoutes::wire($this->app);
        $this->actingAs(AdminUser::create([
            'name' => 'BR3 Owner', 'email' => 'br3-qe@example.test', 'password' => 'password-long-enough', 'role' => 'owner',
        ]), 'admin');
    });

    it('offers every new control with its range and the stylesheet\'s own defaults', function () {
        br3Brand(['header_layout' => ['pill_at' => 'top-left', 'name_m' => 30]]);
        br3Save(['brand_desc_fs' => 17]);

        $ctx = $this->getJson('/admin-api/storefront/context?path='.rawurlencode('/brands/anuabr3/'))->assertOk();

        expect($ctx->json('edit.fields.layout'))->toBe(['pill_at' => 'top-left', 'name_m' => 30])
            ->and($ctx->json('edit.panel.quiet'))->toBe(BrandPanel::QUIET)
            ->and($ctx->json('edit.panel.shop.desc'))->toBe(17)
            ->and($ctx->json('edit.panel.shop.pill_at'))->toBe('bottom-center')
            ->and($ctx->json('edit.panel.ranges.logo_size'))->toBe(['min' => 40, 'max' => 120, 'unit' => 'px'])
            ->and($ctx->json('edit.panel.ranges.card_pad_m'))->toBe(['min' => 6, 'max' => 32, 'unit' => 'px']);
    });

    it('previews unsaved, saves, and hands back the header the page draws', function () {
        $brand = br3Brand();
        $layout = br3Everything();

        $pv = $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}/preview", ['layout' => $layout, 'path' => '/brands/anuabr3/'])->assertOk();
        expect($pv->json('html'))->toContain('brw-ph--px-right brw-ph--py-bottom brw-ph--at-top-center')
            ->and($brand->fresh()->header_layout)->toBeNull();

        $r = $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", ['layout' => $layout, 'path' => '/brands/anuabr3/'])->assertOk();
        expect($brand->fresh()->header_layout)->toEqual($layout)
            ->and($r->json('html'))->toContain('--brw-ph-fnm:26px;--brw-ph-fdm:16px;--brw-ph-lgm:64px;');
    });

    it('refuses each new size out of range and each position that is not an option', function () {
        $brand = br3Brand(['header_layout' => ['name' => 40]]);

        foreach ([['pad' => 7], ['pad' => 61], ['inset' => 121], ['gap' => -1], ['name' => 57], ['desc' => 11], ['logo_size' => 39],
            ['inset_m' => 41], ['gap_m' => 41], ['card_pad_m' => 5], ['name_m' => 13], ['desc_m' => 21], ['logo_size_m' => 81],
            ['panel_x' => 'middle'], ['panel_y' => 'center'], ['pill_at' => 'middle'], ['pill_at' => 'top-left;x'], ['name' => '40px']] as $bad) {
            $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", ['layout' => $bad, 'path' => '/brands/anuabr3/'])
                ->assertStatus(422);
        }

        expect($brand->fresh()->header_layout)->toBe(['name' => 40]);
    });

    it('has a control for every key, on its own device, painting the property the page prints', function () {
        $js = (string) file_get_contents(resource_path('js/kbb/admin/storefront-admin.js'));

        // MUTATION: leave a key out of PANEL_BARS / PANEL_CHOICES and the
        // pop-up cannot set what the server accepts.
        foreach (BrandPanel::RANGES as $key => [, , , $property]) {
            $group = in_array($key, ['height_m', 'lines_m', 'space_top_m', 'space_x_m', ...BR3_PHONE], true) ? 'phone' : 'laptop';
            expect(preg_match("#\\['{$key}', '[^']+', '{$property}', '{$group}'\\]#", $js))->toBe(1, "bar {$key}");
        }

        foreach (BrandPanel::CHOICES as $key => [, $options]) {
            expect(preg_match("#\\['{$key}', '[^']+', 'brw-ph--[a-z-]*', \\[(.*?\\])\\], '(both|laptop|phone)'(, true)?\\]#s", $js, $m))->toBe(1, "choice {$key}");
            preg_match_all("#\\['([a-z-]+)', '[^']+'\\]#", $m[1], $o);
            expect($o[1])->toBe($options, "options of {$key}")
                ->and(($m[3] ?? '') === ', true')->toBe(isset(BrandPanel::QUIET_CHOICES[$key]), "{$key} quiet");
        }

        expect($js)->toContain("if (quiet[key] === Number(v[key])) el.style.removeProperty(prop);");
    });
});
