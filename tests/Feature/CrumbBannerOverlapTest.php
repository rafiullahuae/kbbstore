<?php

declare(strict_types=1);

/**
 * Lane CB -- THE BREADCRUMB WENT BEHIND THE BRAND BANNER.
 *
 * The owner, 8 October: "in mobile, i have enabled the breakcrums, but it goes
 * behind the brand banner. please fix, and give us full control of spacing top
 * and bottom of breadcrums, and also padding. for desktop and mobile both."
 *
 * THE DEFECT, measured in Chromium (tools/cb-crumb.cjs) on a brand page with
 * the trail on and "space above the header" at 0: the header's margin is
 * `space - 22px` (kbb-brand-header.css, OUTER SPACING), written for a page
 * where nothing sat above the header, so it rose 22px -- over the trail. The
 * header is position:relative + isolation:isolate, so it painted on top, and
 * elementFromPoint() at the centre of every crumb link answered
 * `brw-ph__img` at 390 and at 1280: 8 of 8 points blocked, 22px of overlap.
 * After: 0 blocked, 0 overlap, on brand, category, shop and Arabic pages.
 *
 * THE FIX (HeaderSettings::breadcrumbCss): wherever the trail is SHOWN, the
 * header straight after it may not rise above it -- the pull is clamped at 0,
 * per device, the header's own container query choosing which "space above"
 * applies. Where the trail is hidden nothing changes. The category banner sits
 * in a box of its own under the trail and cannot rise over it at all.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Services\HeaderSettings;
use App\Services\SettingsService;
use App\Services\SiteLayout;

function cboSet(array $header = [], array $layout = []): void
{
    if ($header !== []) {
        app(HeaderSettings::class)->save($header);
    }
    if ($layout !== []) {
        app(SiteLayout::class)->save($layout);
    }
    Setting::flushMap();
    SettingsService::forgetMemo();
}

function cboBrandPage(): string
{
    if (! Brand::query()->where('slug', 'cbo-anua')->exists()) {
        $b = Brand::create(['name' => 'Anua', 'slug' => 'cbo-anua', 'header_image' => '/uploads/brands/a.jpg']);
        Product::create(['slug' => 'cbo-p', 'name' => 'CBO Toner', 'brand_id' => $b->id, 'price' => 1000, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple']);
    }
    Setting::flushMap();
    SettingsService::forgetMemo();

    return (string) test()->get('/brands/cbo-anua/')->assertOk()->getContent();
}

function cboStyle(string $html): string
{
    return preg_match('#<style id="kbb-crumbs">(.*?)</style>#s', $html, $m) === 1 ? $m[1] : '';
}

const CBO_CLAMP = ':root body .brw-crumb+.brw-phw .brw-ph{margin-top:max(0px,calc(var(--brw-ph-st,22px) - 22px))}';

it('keeps the brand banner from rising over a breadcrumb that is shown', function () {
    /*
     * MUTATION, RUN: take `$noRise` out of the "on" branch of breadcrumbCss()
     * and the first expectation is red -- and in Chromium the crumb is behind
     * the banner again (8 of 8 points blocked at space above 0).
     */
    cboSet(['bc_mobile' => true, 'bc_desktop' => true], ['brand_space_top' => 0, 'brand_space_top_m' => 0]);
    $html = cboBrandPage();
    $css = cboStyle($html);

    expect(substr_count($css, CBO_CLAMP))->toBe(2)
        ->and($css)->toContain('@container (max-width:599px){:root body .brw-crumb+.brw-phw .brw-ph{margin-top:max(0px,calc(var(--brw-ph-stm,22px) - 22px))}}')
        // Printed escaped and still the same bytes: no character in it that {{ }} rewrites.
        ->and($css)->toBe(app(HeaderSettings::class)->breadcrumbCss())
        ->and($css)->not->toContain('&gt;');

    /*
     * The rule only reaches a header that follows the trail DIRECTLY, so the
     * markup has to keep them adjacent: a wrapper or a stray element between
     * the two and the clamp silently stops applying.
     *
     * MUTATION: put any element between the breadcrumb <nav> and the header in
     * store/brands.blade.php and this is red.
     */
    expect($html)->toMatch('#<nav class="brw-crumb"[^>]*>.*?</nav>\s*(?:<!--.*?-->\s*)*<div class="brw-phw" data-kbb-brand-header>#s');
});

it('leaves the page alone where the breadcrumb is hidden, and at the shipped spacing', function () {
    /*
     * Off (the shipped state), the owner's "space above" may still pull the
     * header up into the page's top padding, as it did -- no clamp printed.
     * On one device only, only that device's block carries it.
     *
     * MUTATION: print the clamp in the "off" branch too and the first line is
     * red; the owner's tuned brand page without breadcrumbs would move.
     */
    cboSet(['bc_mobile' => false, 'bc_desktop' => false]);
    expect(cboStyle(cboBrandPage()))->not->toContain('brw-phw');

    cboSet(['bc_mobile' => true, 'bc_desktop' => false]);
    $css = app(HeaderSettings::class)->breadcrumbCss();
    [$phone, $desktop] = explode('@media (min-width:901px)', $css);

    expect(substr_count($phone, CBO_CLAMP))->toBe(1)
        ->and($desktop)->not->toContain('brw-phw');
});

it('keeps the category banner in its own box under the breadcrumb', function () {
    /*
     * MUTATION: move the banner's include inside the breadcrumb's .wrap in
     * store/shop.blade.php and this is red -- and "space above" under 22 would
     * pull it over the trail exactly as on the brand page.
     */
    // A real picture: the banner draws only one that is on this server.
    $rel = 'uploads/cbo-test/c.jpg';
    @mkdir(\dirname(public_path($rel)), 0775, true);
    $im = imagecreatetruecolor(800, 250);
    imagejpeg($im, public_path($rel), 60);
    imagedestroy($im);

    try {
        $c = Category::query()->create(['name' => 'Cleansers', 'slug' => 'cbo-cat', 'parent_id' => null, 'header_image' => '/'.$rel]);
        $c->forceFill(['path' => $c->slug, 'depth' => 0])->save();
        cboSet(['bc_mobile' => true, 'bc_desktop' => true]);

        $html = (string) test()->get('/collections/cbo-cat/')->assertOk()->getContent();
    } finally {
        @unlink(public_path($rel));
        @rmdir(\dirname(public_path($rel)));
    }

    expect($html)->toMatch('#<div class="crumb">.*?</div>\s*</div>\s*<div class="wrap kbb-cbw">\s*<div class="brw-phw"#s')
        ->and(\App\Support\BrandPanel::CATEGORY_CSS)->toStartWith('.kbb-cbw{padding-top:22px}');
});

/* ═══════════════════════ the new spacing and padding controls ═══════════════════════ */

it('adds inner padding for each device, clamped, as one CSS rule per device', function () {
    /*
     * "full control of spacing top and bottom of breadcrums, and also padding.
     * for desktop and mobile both." Space above and below stay where they
     * were; with any padding set they become the trail's margins and the
     * padding its own.
     *
     * MUTATION, RUN: set POLICY 'clamp' to false and the 99 below is printed
     * as 99px; read `bc_pad_x` for the phone and the phone block is wrong.
     */
    cboSet(['bc_mobile' => true, 'bc_desktop' => true,
        'bc_above_mobile' => 10, 'bc_below_mobile' => 4, 'bc_pad_y_mobile' => 6, 'bc_pad_x_mobile' => 99,
        'bc_above' => 20, 'bc_below' => 8, 'bc_pad_y' => '<b>', 'bc_pad_x' => 12]);

    $c = app(HeaderSettings::class)->all();
    expect($c['bc_pad_x_mobile'])->toBe(40)
        ->and($c['bc_pad_y'])->toBe(0)
        ->and($c['bc_pad_y_mobile'])->toBe(6);

    $sel = ':root body :is(.crumb,.brw-crumb,.rtn-crumb)';
    $css = app(HeaderSettings::class)->breadcrumbCss();

    expect($css)->toContain('@media (max-width:900px){'.$sel.'{margin-top:10px;margin-bottom:4px;padding:6px 40px}')
        ->and($css)->toContain('@media (min-width:901px){'.$sel.'{margin-top:20px;margin-bottom:8px;padding:0px 12px}');

    foreach (['bc_pad_y_mobile', 'bc_pad_x_mobile', 'bc_pad_y', 'bc_pad_x'] as $key) {
        expect(HeaderSettings::SCHEMA[$key][0])->toBe('range')
            ->and(HeaderSettings::SCHEMA[$key][2])->toBe(0)
            ->and(HeaderSettings::SCHEMA[$key][4])->toMatchArray(['min' => 0, 'max' => 40, 'unit' => 'px'])
            ->and(HeaderSettings::TABS['crumbs'][2])->toContain($key);
    }
});

it('prints the very bytes it printed before while every padding is 0', function () {
    /*
     * The defaults reproduce today's spacing exactly: with the four paddings
     * at 0 the trail's own rule is the 2.60.342 string, word for word, and
     * only the overlap clamp is added beside it.
     *
     * MUTATION: drop the `$padY === 0 && $padX === 0` branch and the trail's
     * rule turns into margins -- red here.
     */
    cboSet(['bc_mobile' => true, 'bc_desktop' => true]);
    $sel = ':root body :is(.crumb,.brw-crumb,.rtn-crumb)';
    $css = app(HeaderSettings::class)->breadcrumbCss();

    expect($css)->toStartWith('@media (max-width:900px){'.$sel.'{margin-top:0;margin-bottom:0;padding-top:18px;padding-bottom:0px}')
        ->and($css)->toContain('@media (min-width:901px){'.$sel.'{margin-top:0;margin-bottom:0;padding-top:18px;padding-bottom:0px}');

    cboSet(['bc_mobile' => false, 'bc_desktop' => false]);
    expect(app(HeaderSettings::class)->breadcrumbCss())->toBe(
        '@media (max-width:900px){'.$sel.'{display:none}'.$sel.'+:is(.eyebrow,.sh){margin-top:18px}}'
        .'@media (min-width:901px){'.$sel.'{display:none}'.$sel.'+:is(.eyebrow,.sh){margin-top:18px}}'
    );
});
