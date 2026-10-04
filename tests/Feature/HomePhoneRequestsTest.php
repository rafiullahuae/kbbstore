<?php

declare(strict_types=1);

/**
 * THREE OWNER REQUESTS OF 4 OCTOBER, ALL HOMEPAGE, ALL SHIPPED ON.  (Lane PF)
 *
 *   A  About us: "i want a read more faded functionality which we have on
 *      product page on short and long description need the same. also in
 *      mobile."
 *   B  Brands on a phone: "the brands boxes need to be little bit squeezed,
 *      reduce the height" — 74px tiles, now 54px.
 *   C  Phone carousels: "show 2.2, 2.3 2.5 etc products … have option to hide
 *      un-hide the arrows in mobile, keep off in mobile".
 *
 * Measured in Chromium (storage/pf-logs/peek.cjs): at 360, 390 and 414 both
 * carousels show 2.30 cards, the first on the gutter, the track running to the
 * screen edge, no arrows, scrollWidth equal to the viewport; brand tiles 54px;
 * About clamped at 200px on a phone and 535px open.
 *
 * Every case says what the defect looked like and how to turn it red.
 */

use App\Services\HomepageContent;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Services\SpottedSettings;
use App\Support\HomeBundles;
use App\Support\HomeSections;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
});

function pfpWrite(array $values): void
{
    ModuleSchema::write(app(SettingsService::class), 'homepage_content', HomepageContent::SCHEMA, $values);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function pfpHome(): string
{
    SettingsService::forgetMemo();
    Cache::flush();

    return test()->get('/')->assertOk()->getContent();
}

function pfpCss(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb.css')));
}

/* ═══ A. ABOUT US — READ MORE ═════════════════════════════════════════════ */

it('clamps About us behind the product page\'s Read more, with the whole text still in the page', function () {
    /*
     * THE DEFECT, on the shop: four paragraphs of About us at full length, no
     * Read more — where the product page fades the description and offers
     * "Read more ↓". And the trap on the way to fixing it: printing only the
     * first paragraph and loading the rest, which would take the owner's text
     * away from search engines.
     *
     * MUTATION (RUN): default `home_ab_more` to false → red on the button.
     */
    $html = pfpHome();
    $about = preg_match('#<section class="sec hs hs-about.*?</div></div></section>#s', $html, $m) === 1 ? $m[0] : '';

    expect($about)->toContain('<div class="hs-aboutin" data-readmore>')
        ->toContain('<div class="hs-abtext clamp" id="hs-about-text">')
        ->toContain('</div><button class="readmore hs-abmore" type="button" aria-expanded="false" aria-controls="hs-about-text">Read more ↓</button>')
        ->toContain('--hs-ab-cl-d:140px;--hs-ab-cl-m:200px');

    // All four paragraphs, as text, inside the clamped block.
    expect(substr_count($about, '<p>'))->toBe(4)
        ->and($about)->toContain('Your journey to flawless, radiant skin starts here!');

    // Off: the whole text, open, no button, no clamp.
    pfpWrite(['home_ab_more' => false]);
    $off = pfpHome();
    expect($off)->not->toContain('hs-abmore')->not->toContain('hs-abtext clamp')->not->toContain('--hs-ab-cl-d')
        ->and(substr_count($off, 'Your journey to flawless, radiant skin starts here!'))->toBe(1);
});

it('uses the product description\'s own toggle, which flips classes and measures nothing', function () {
    /*
     * THE DEFECT this guards: a second, copied Read more that drifts from the
     * product page's (different words, no Read less, or one that measures the
     * text with scrollHeight — CLAUDE.md rule 4).
     *
     * MUTATION (RUN): remove initReadMore from app.js's STEPS → red (the
     * homepage button would do nothing).
     */
    $tabs = (string) preg_replace(['~/\*.*?\*/~s', '~(^|\s)//[^\n]*~m'], ['', '$1'], (string) file_get_contents(resource_path('js/kbb/tabs.js')));
    $app = (string) file_get_contents(resource_path('js/kbb/app.js'));

    expect($tabs)->toContain('export function toggleReadMore(more, root)')
        ->and(substr_count($tabs, 'toggleReadMore(more, root)'))->toBe(3) // defined, product tabs, [data-readmore]
        ->and($tabs)->toContain("querySelectorAll('[data-readmore]')")
        ->and($tabs)->toContain("more.setAttribute('aria-expanded', open ? 'true' : 'false')");

    preg_match('/const STEPS = \[(.*?)\];/s', $app, $steps);
    expect($steps[1] ?? '')->toContain('initReadMore');

    foreach (['getBoundingClientRect', 'offsetHeight', 'scrollHeight', 'clientHeight', 'getComputedStyle'] as $api) {
        expect($tabs)->not->toContain($api);
    }

    // The height and the fade are CSS, per device, from the two selects.
    $css = pfpCss();
    expect($css)->toContain('.kbb-home .hs-abtext.clamp{max-height:var(--hs-ab-cl-d,140px);overflow:hidden;')
        ->and($css)->toContain('.kbb-home .hs-abtext.clamp{max-height:var(--hs-ab-cl-m,200px)}')
        ->and($css)->toContain('mask-image:linear-gradient(180deg,#000 calc(100% - 64px),transparent)');

    // …and the built bundle the page loads carries it.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = (string) file_get_contents(public_path('build/'.$manifest['resources/js/kbb/app.js']['file']));
    expect($built)->toContain('[data-readmore]');
});

it('stores the clamp heights only as their own options', function () {
    // MUTATION: drop the pick() around home_ab_clamp_m → the raw value reaches the style attribute, red.
    pfpWrite(['home_ab_clamp_m' => '280', 'home_ab_clamp_d' => '9999;background:url(x)']);
    $ab = HomeSections::about(HomeSections::settings(), 'x');

    expect($ab['style'])->toContain('--hs-ab-cl-d:140px;--hs-ab-cl-m:280px')->not->toContain('url(');
});

/* ═══ B. BRANDS ON A PHONE — SHORTER TILES ════════════════════════════════ */

it('draws the phone brand tiles 54px tall, from a select on the Brands tab', function () {
    /*
     * THE DEFECT, on the shop: 74px logo tiles on a phone — "need to be little
     * bit squeezed, reduce the height".
     *
     * MUTATION (RUN): put `height:74px` back in the phone `.hs-brand` rule →
     * red.
     */
    expect(HomeSections::brands([])['style'])->toEndWith(';--hs-br-th-m:54px')
        ->and(HomepageContent::TABS['brands'][2])->toContain('home_br_th_m');

    $css = pfpCss();
    expect($css)->toContain('.kbb-home .hs-brand{display:grid;place-items:center;height:var(--hs-br-th-m,54px);')
        ->and($css)->toContain('max-height:calc(var(--hs-br-th-m,54px) - 14px)')
        ->and($css)->not->toContain('place-items:center;height:74px');

    pfpWrite(['home_br_th_m' => '48']);
    expect(HomeSections::brands(HomeSections::settings())['style'])->toEndWith(';--hs-br-th-m:48px');
    pfpWrite(['home_br_th_m' => '54px;x']);
    expect(HomeSections::brands(HomeSections::settings())['style'])->toEndWith(';--hs-br-th-m:48px');
});

/* ═══ C. PHONE CAROUSELS — 2.3 IN VIEW, NO ARROWS ═════════════════════════ */

it('shows 2.3 cards and no arrows on a phone in both homepage carousels by default', function () {
    /*
     * THE DEFECT, on the shop: two whole cards and arrows on a phone, nothing
     * telling a thumb there is more — "so the user will know that there's
     * more products in scroll via such 2.3 etc display".
     *
     * MUTATION (RUN): default `home_hb_per_m` back to '2' → red.
     */
    $b = HomeBundles::config();
    expect($b['style'])->toContain('--bndl-per-m:2.3')
        ->and($b['classes'])->toContain('bndl-peek-m')->toContain('bndl-noarr-m');

    $s = app(SpottedSettings::class)->section();
    expect($s['style'])->toContain('--spt-per-m:2.3')
        ->and($s['classes'])->toContain('spt-peek-m')->toContain('spt-noarr-m');

    // The options he named, beside the ones that were there.
    expect(array_map('strval', array_keys(HomepageContent::SCHEMA['home_hb_per_m']['options'])))->toBe(['1', '1.5', '2', '2.2', '2.3', '2.5'])
        ->and(array_map('strval', array_keys(SpottedSettings::SCHEMA['per_m']['options'])))->toBe(['1', '1.5', '2', '2.2', '2.3', '2.5']);

    // A whole number keeps the old box, cut at the gutter.
    pfpWrite(['home_hb_per_m' => '2', 'home_hb_arrows_m' => true]);
    $b = HomeBundles::config();
    expect($b['classes'])->not->toContain('bndl-peek-m')->not->toContain('bndl-noarr-m');
});

it('sizes the peek in CSS from the per-view number — two cards, two gaps and three tenths at 2.3', function () {
    /*
     * THE DEFECT: the old formula, (100% − (per − 1) × gap) / per, puts 2.25
     * cards on a 390px screen, cut by the gutter rather than the screen; and a
     * script that measured the track to fix it would break rule 4.
     *
     * MUTATION (RUN): drop the `round(up, …)` declaration → the fallback
     * formula remains and this is red.
     */
    $css = pfpCss();

    expect($css)->toContain('grid-auto-columns:calc((100% + var(--hs-peek-bleed) - (round(up, var(--bndl-per-m), 1) - 1) * var(--kbb-gap)) / var(--bndl-per-m))')
        ->and($css)->toContain('grid-auto-columns:calc((100% + var(--hs-peek-bleed) + 6px - (round(up, var(--spt-per), 1) - 1) * var(--spt-gap)) / var(--spt-per))')
        ->and($css)->toContain('.kbb-home .bndl.bndl-car-m.bndl-peek-m .bndl-track{margin-inline-end:calc(-1 * var(--hs-peek-bleed));padding-inline-end:var(--hs-peek-bleed);')
        ->and($css)->toContain('scroll-snap-stop:always');
});

it('moves a SAVED shop to the owner\'s answer, and writes nothing where nothing was saved', function () {
    /*
     * THE DEFECT: a default that never reaches the live shop. Appearance →
     * Homepage content saves every field, so his shop holds per_m = 2 and the
     * arrows on as rows, and a new default changes nothing for him.
     *
     * MUTATION (RUN): delete the migration's set() → red.
     */
    $s = app(SettingsService::class);
    $s->set('home_hb_per_m', '2');
    $s->set('home_hb_arrows_m', true);
    $s->set('spotted_per_m', '1.5');

    (require database_path('migrations/2027_07_28_200100_home_phone_carousels_peek.php'))->up();
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();

    expect(HomeBundles::config()['style'])->toContain('--bndl-per-m:2.3')
        ->and(HomeBundles::config()['classes'])->toContain('bndl-noarr-m')
        ->and(app(SpottedSettings::class)->section()['style'])->toContain('--spt-per-m:2.3')
        ->and(\Illuminate\Support\Facades\DB::table('settings')->where('key', 'spotted_arrows_m')->exists())->toBeFalse();
});
