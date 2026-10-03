<?php

declare(strict_types=1);

/**
 * Homepage "Big savings bundles": a carousel with arrows, the heading centred,
 * the All sets button redesigned — under the carousel on a phone.  (2.60.370)
 *
 * THE OWNER: "on homepage, i need the bundle section to be carousel with proper
 * beautiful arrows, give controls of everything for desktop mobile both. center
 * the heading, redesign the All Sets button beautifully" and "i want this
 * button in mobile at bottom of carsousel, give controls of spacing etc."
 * Controls: Appearance → Homepage content → Big savings bundles.
 *
 * Two defects this pins, both found on the preview before shipping:
 *   · the first cut named the section `.hb`, and `.kbb-home .hb h2` is the hero
 *     banner's white headline — the heading went white on cream;
 *   · it used var(--pink-d), which the homepage never defines, so the button's
 *     arrow circle had no background at all.
 *
 * MUTATION NOTES, RUN:
 *   · print 'hb' instead of 'bndl' in HomeBundles::config() → RED (first case).
 *   · put var(--pink-d) back in the button rule → RED (third case).
 */

use App\Services\HomepageContent;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Support\HomeBundles;

function bndlRules(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb.css')));
}

it('ships the owner\'s defaults: carousel on both, heading centred, button beside the heading on a laptop and under the carousel on a phone', function () {
    $c = HomeBundles::config();

    expect($c['classes'])->toBe('bndl bndl-car-d bndl-car-m bndl-center bndl-btn-d-top bndl-btn-m-bottom')
        ->and($c['style'])->toBe('--bndl-per-d:4;--bndl-per-m:2;--bndl-pad-d:8px;--bndl-pad-m:8px;--bndl-hg-d:24px;--bndl-hg-m:12px;--bndl-bg-d:24px;--bndl-bg-m:16px')
        ->and($c['url'])->toBe('/shop/?cat=skincare-sets')
        ->and($c['auto'])->toBe(0);

    // Not the hero banner's class, whose h2 is white.
    expect(bndlRules())->toContain('.kbb-home .hb h2{')->and($c['classes'])->not->toMatch('/(^| )hb( |$)/');
});

it('stores only its own options and refuses a link that is not this shop or https', function () {
    ModuleSchema::write(app(SettingsService::class), 'homepage_content', HomepageContent::SCHEMA, [
        'home_hb_layout_d' => 'grid', 'home_hb_per_m' => '1.5', 'home_hb_btn_m' => 'off', 'home_hb_arrows_d' => false,
        'home_hb_btn_url' => 'javascript:alert(1)', 'home_hb_btn_text' => '<b>See every set</b>', 'home_hb_pad_d' => '999',
    ]);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    $c = HomeBundles::config();
    expect($c['classes'])->toBe('bndl bndl-grid-d bndl-car-m bndl-noarr-d bndl-center bndl-btn-d-top bndl-btn-m-off')
        ->and($c['style'])->toContain('--bndl-per-m:1.5')->toContain('--bndl-pad-d:8px')
        ->and($c['url'])->toBe(HomeBundles::DEFAULT_URL)
        ->and($c['label'])->toBe('See every set');
});

it('draws the carousel on the homepage with the shared arrows and the button in both places', function () {
    $css = bndlRules();
    expect($css)->toContain('background:linear-gradient(135deg,var(--pink),var(--pink-deep))')
        ->and($css)->not->toMatch('/\.bndl[^{]*\{[^}]*var\(--pink-d\)/')
        ->and($css)->toContain('.kbb-home .bndl.bndl-btn-m-bottom .bndl-foot{display:flex;margin-top:var(--bndl-bg-m)}');

    $home = (string) file_get_contents(resource_path('views/store/home.blade.php'));
    expect(substr_count($home, 'data-ymal-prev aria-controls="bndl-track"'))->toBe(1)
        ->and(substr_count($home, 'class="bndl-all bndl-all-bottom"'))->toBe(1)
        ->and(substr_count($home, "'trackLabel' => \$bndlTitle"))->toBe(1);

    // The tab the owner edits it on.
    expect(HomepageContent::TABS['bundles'][0])->toBe('Big savings bundles');
});
