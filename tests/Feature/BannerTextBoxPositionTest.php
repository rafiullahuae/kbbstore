<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Support\BannerTextBox;
use Illuminate\Support\Facades\Hash;

/*
 * Lane HB2 -- where the banner's text box sits.
 *
 * The owner, with a screenshot of style D starting well to the LEFT of a red
 * line he drew down the logo's edge: "i can't find the control for the box
 * that it should not go outside the site width. and also the position for the
 * box like bottom, middle and a custom positioning by setting up the
 * percentage or px. same for mobile."
 *
 * The render helpers (hbSet, hbWords, hbRender) are BannerTextBoxTest's.
 */
require_once __DIR__.'/../Support/BannerTextBoxHelpers.php';

function hbCss(): string
{
    return (string) file_get_contents(resource_path('views/partials/home/slider-text-box-css.blade.php'));
}

/* ═════════════════════ inside the site width ═══════════════════════════════ */

it('measures the box from the same edge the header\'s container uses', function () {
    /*
     * THE DEFECT: the box was inset from the PICTURE's edge (60-80px on a
     * computer), and the homepage banner is full-bleed, so on a wide screen it
     * started far outside the logo's line. The header's .wrap is
     * max-width:var(--site-max), centred, padded var(--site-gutter); the box's
     * inset must be built from those two and nothing else.
     *
     * MUTATION: write the inset without `+ var(--hb-gs)`, drop the max() floor,
     * or read --site-gutter instead of --mh-l under 900px, and this is red.
     */
    $kbb = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $css = hbCss();
    $start = '--hb-ins:max(var(--hb-pads),(100cqi - var(--hb-site)) / 2 + var(--hb-gs))';
    $end = '--hb-ine:max(var(--hb-pade),(100cqi - var(--hb-site)) / 2 + var(--hb-ge))';

    // The header's own rules: width --hd-max, padding --site-gutter, and --mh-l/--mh-r at 900px and under.
    expect($kbb)->toContain('header .wrap{max-width:var(--hd-max)}')
        ->and($kbb)->toContain('.wrap{max-width:var(--site-max);margin-inline:auto;padding-inline:var(--site-gutter)}')
        ->and($kbb)->toMatch('/@media\(max-width:900px\)\{\s*header > \.wrap\{padding-inline-start:var\(--mh-l,22px\);padding-inline-end:var\(--mh-r,22px\)\}/')
        // ...and the box's, built from the same three.
        ->and(substr_count($css, '.kbbs.has-hb.hb-site-d{'.$start.';'.$end.'}'))->toBe(1)
        ->and(substr_count($css, '.kbbs.has-hb.hb-site-m{'.$start.';'.$end.'}'))->toBe(1)
        ->and($css)->toContain('--hb-site:var(--hb-hdmax,var(--site-max,1680px))')
        ->and($css)->toContain('--hb-gs:var(--site-gutter,22px);--hb-ge:var(--site-gutter,22px)')
        ->and($css)->toContain('@media (max-width:900px){.kbbs.has-hb{--hb-gs:var(--hb-mhl,12px);--hb-ge:var(--hb-mhr,12px)}}')
        ->and($css)->toContain('.kbb-secw-bleed .kbbs.has-hb{--hb-pads:var(--hb-gs);--hb-pade:var(--hb-ge)}');

    // The numbers come from the header's own services, not a second guess.
    $mh = app(\App\Services\MobileHeader::class)->all();
    expect(BannerTextBox::siteVariables(BannerTextBox::normalize([])))
        ->toBe(';--hb-hdmax:'.app(\App\Services\HeaderSettings::class)->maxWidthCss().';--hb-mhl:'.$mh['pad_left'].'px;--hb-mhr:'.$mh['pad_right'].'px')
        ->and(BannerTextBox::siteVariables(BannerTextBox::normalize(['inside_d' => false, 'inside_m' => false])))->toBe('');

    /*
     * And the arithmetic, at the widths the owner looks at: the header's
     * content edge minus the full-bleed frame's own left edge (the banner
     * section is capped at 1920px and centred) is what the formula gives.
     * Measured in Chromium as well (tools/hb2-align.cjs): equal to the pixel
     * at 390, 768, 1280, 1600 and 1920, English and Arabic.
     */
    $site = 1680;

    foreach ([390, 768, 900, 1280, 1600, 1920, 2560] as $vw) {
        $pad = $vw <= 900 ? 12 : 22;                         // --mh-l under 900px, --site-gutter above
        $logo = ($vw - min($vw, $site)) / 2 + $pad;          // the header .wrap's content edge
        $frameLeft = ($vw - min($vw, 1920)) / 2;             // .kbb-secw-bleed > .wrap, max 1920, centred
        $frame = min($vw, 1920);                              // 100cqi

        expect((float) max($pad, ($frame - $site) / 2 + $pad))->toBe((float) ($logo - $frameLeft), "at {$vw}px");
    }
});

it('ships the box inside the site width on both devices, and off restores the picture-edge inset', function () {
    /*
     * "inside site width" is the ONE default this lane moved, because he asked
     * for it. MUTATION: ship POSITION['inside'] false and the classes vanish.
     */
    $on = hbRender(hbSet([], 1, [1 => hbWords()]));
    $off = hbRender(hbSet(['text_box' => json_encode(['inside_d' => false, 'inside_m' => false])], 1, [1 => hbWords()]));

    $cls = fn (string $html): string => preg_match('/<div class="(kbbs [^"]*)"/', $html, $m) ? $m[1] : '';

    expect($cls($on))->toContain('hb-site-d')->toContain('hb-site-m')
        ->and($cls($off))->toContain('has-hb')->not->toContain('hb-site-')
        // Off: the inset is exactly the one 2.60.432 shipped.
        ->and(hbCss())->toContain('--hb-in:clamp(60px,5.5cqi,80px)')
        ->and(hbCss())->toMatch('/\.kbbs\.has-hb\{[^}]*--hb-in:16px;/');
});

/* ═════════════════════ vertical ═══════════════════════════════════════════ */

it('turns each vertical mode into the spacer numbers that place the box', function (array $cfg, string $dev, array $want) {
    /*
     * The spacer above gets flex-grow vg1 and the one below vg2, so the box's
     * top is inset + vg1 x (free height). MUTATION: swap the 0 and 1 in the
     * top/bottom map in positionNumbers() and the first two rows are red.
     */
    $n = BannerTextBox::positionNumbers(BannerTextBox::normalize($cfg), $dev);

    expect([$n['vg1'], $n['vb'], $n['vg2']])->toBe($want);
})->with([
    'top' => [['vpos_d' => 'top'], 'd', ['0', '0px', '1']],
    'bottom' => [['vpos_d' => 'bottom'], 'd', ['1', '0px', '0']],
    'middle' => [['vpos_m' => 'middle'], 'm', ['0.5', '0px', '0.5']],
    'auto, style A, computer -> bottom (as shipped)' => [[], 'd', ['1', '0px', '0']],
    'auto, style D, computer -> middle (as shipped)' => [['style' => 'd'], 'd', ['0.5', '0px', '0.5']],
    'auto, style D, phone -> bottom (as shipped)' => [['style' => 'd'], 'm', ['1', '0px', '0']],
    'custom 25%' => [['vpos_d' => 'custom', 'vval_d' => 25, 'vunit_d' => 'pct'], 'd', ['0.25', '0px', '0.75']],
    'custom 40px' => [['vpos_m' => 'custom', 'vval_m' => 40, 'vunit_m' => 'px'], 'm', ['0', '40px', '1']],
]);

it('keeps the box inside the banner at any custom value, without knowing its height', function () {
    /*
     * The two techniques, pinned in the stylesheet: flex-grow shares the FREE
     * height (column minus box) so 0..1 can only travel inside it, and a px
     * offset is the upper spacer's basis with shrink 1, so it gives way before
     * the box would cross the bottom inset. MUTATION: make the spacer
     * `flex:0 0 var(--hb-vb)` (no shrink) and the px clamp is gone -- red.
     */
    expect(hbCss())->toContain('.hb-pos::before{content:"";flex:var(--hb-vg1) 1 var(--hb-vb)}')
        ->and(hbCss())->toContain('.hb-pos::after{content:"";flex:var(--hb-vg2) 1 0px}')
        ->and(hbCss())->toMatch('/\.hb-box\{position:relative;flex:none;/');

    $n = BannerTextBox::normalize(['vpos_d' => 'custom', 'vunit_d' => 'pct', 'vval_d' => 150, 'vval_m' => 9999, 'vunit_m' => 'px', 'vpos_m' => 'custom']);

    expect($n['vval_d'])->toBe(100)->and($n['vval_m'])->toBe(600)
        ->and(BannerTextBox::positionNumbers($n, 'd')['vg1'])->toBe('1');
});

it('keeps style D\'s computer centre exactly where 2.60.432 drew it, and its sticker inside at Top', function () {
    /*
     * D was `top:calc(50% + 12px)` with translateY(-50%). In the column, a
     * centred box's middle is (H + top - bottom) / 2, so a top inset of
     * bottom + 24px puts it at H/2 + 12 -- the same pixels. Any other vertical
     * mode uses the ordinary top inset plus the sticker's overhang.
     * MUTATION: drop the hb-va-d rule and D moves 12px+.
     */
    // ▲ Lane HB4: anchored to the AUTO bottom (--hb-bot0), so a "space below"
    // he sets moves only the bottom edge, not D's centre.
    expect(hbCss())->toContain('.hb-d.hb-va-d .hb-pos{inset-block-start:calc(var(--hb-bot0) + 24px)}')
        ->and(hbCss())->toContain('inset-block:calc(var(--hb-tb) + var(--hb-o)) var(--hb-bot)')
        ->and(BannerTextBox::rootClasses(BannerTextBox::normalize([])))->toContain('hb-va-d')
        ->and(BannerTextBox::rootClasses(BannerTextBox::normalize(['vpos_d' => 'top'])))->not->toContain('hb-va-d');
});

/* ═════════════════════ horizontal ═════════════════════════════════════════ */

it('turns each horizontal mode into an offset clamped between the column\'s edges', function (array $cfg, string $dev, string $xp, string $xo, bool $side) {
    /*
     * MUTATION: drop the clamp() round the box's margin-inline-start and a
     * 1000px custom offset pushes it off the banner -- the CSS assertion below
     * is red; map centre to 1 and its row is red.
     */
    $n = BannerTextBox::normalize($cfg);
    $p = BannerTextBox::positionNumbers($n, $dev);

    expect([$p['xp'], $p['xo']])->toBe([$xp, $xo])
        ->and(str_contains(BannerTextBox::rootClasses($n), 'hb-hs-'.$dev))->toBe($side);
})->with([
    'auto: each picture\'s side' => [[], 'd', '0', '0px', true],
    'start' => [['hpos_d' => 'start'], 'd', '0', '0px', false],
    'centre' => [['hpos_m' => 'centre'], 'm', '0.5', '0px', false],
    'end' => [['hpos_d' => 'end'], 'd', '1', '0px', false],
    'custom 30px from the picture\'s side' => [['hpos_d' => 'custom', 'hval_d' => 30, 'hunit_d' => 'px'], 'd', '0', '30px', true],
    'custom 12%' => [['hpos_m' => 'custom', 'hval_m' => 12, 'hunit_m' => 'pct'], 'm', '0', '12%', true],
]);

it('clamps the horizontal margin in the stylesheet and mirrors it for pictures set to End', function () {
    expect(hbCss())->toContain('margin-inline-start:clamp(0px,var(--hb-xo) + (100% - var(--hb-rm)) * var(--hb-xp),100% - var(--hb-rm))')
        ->and(hbCss())->toContain('.hb-hs-d .hb-box.is-end{margin-inline-start:clamp(0px,100% - var(--hb-rm) - var(--hb-xo),100% - var(--hb-rm))}')
        ->and(hbCss())->toContain('.hb-hs-m .hb-box.is-end{margin-inline-start:clamp(0px,100% - var(--hb-rm) - var(--hb-xo),100% - var(--hb-rm))}');
});

/* ═════════════════════ invalid values fall back ═══════════════════════════ */

it('falls back to the shipped value for anything that is not one of its own options', function () {
    /*
     * MUTATION: drop the array_key_exists() check on vpos in normalize() and
     * 'left' is stored, printed as no known mode -- red.
     */
    $n = BannerTextBox::normalize(['vpos_d' => 'left', 'hpos_m' => '"><x>', 'vunit_d' => 'em', 'hunit_d' => ['px'],
        'inside_d' => 'maybe', 'vval_d' => 'x;}', 'hval_m' => -40, 'hval_d' => 5000, 'hunit_m' => 'px']);

    expect($n['vpos_d'])->toBe('auto')->and($n['hpos_m'])->toBe('auto')->and($n['vunit_d'])->toBe('pct')
        ->and($n['hunit_d'])->toBe('px')->and($n['inside_d'])->toBeTrue()->and($n['vval_d'])->toBe(50)
        ->and($n['hval_m'])->toBe(0)->and($n['hval_d'])->toBe(1000);

    // Whatever arrives, the custom properties are numbers with a unit this class wrote.
    foreach ([[], $n, ['vpos_d' => 'custom', 'vval_d' => '30px;}x{', 'hpos_m' => 'custom', 'hval_m' => '1e9']] as $cfg) {
        expect(BannerTextBox::cssVariables($cfg))->toMatch('/^(--hb-[a-z0-9]+-[dm]:-?[0-9.]+(px|%)?;?)+$/');
    }
});

it('refuses an unknown position through the admin endpoint and clamps a number', function () {
    $user = AdminUser::create(['name' => 'HB2', 'email' => 'hb2-'.uniqid().'@example.com', 'password' => Hash::make('secret-secret'), 'role' => 'owner']);
    test()->actingAs($user, 'admin');
    $set = hbSet([], 1);

    test()->putJson('/admin-api/banners/sets/'.$set->id, ['tb_vpos_d' => 'left'])->assertStatus(422);
    test()->putJson('/admin-api/banners/sets/'.$set->id, ['tb_hunit_m' => 'em'])->assertStatus(422);
    test()->putJson('/admin-api/banners/sets/'.$set->id, ['tb_vpos_d' => 'custom', 'tb_vval_d' => 999, 'tb_vunit_d' => 'pct', 'tb_inside_m' => false])
        ->assertOk()->assertJsonPath('set.tb_vval_d', 100)->assertJsonPath('set.tb_inside_m', false);
});

/* ═════════════════════ nothing else moved ═════════════════════════════════ */

it('changes nothing for a slider with no words, and prints the wrapper only round a box', function () {
    /*
     * The no-words page is pinned byte for byte against the template with
     * every Lane HB print removed (BannerTextBoxTest). Here: the position
     * classes and numbers ride on the same `$hbAny` guard.
     * MUTATION: print rootClasses() outside the $hbAny ternary -- red.
     */
    $bare = hbRender(hbSet([], 2));

    expect($bare)->not->toContain('hb-site-')->not->toContain('hb-pos')->not->toContain('--hb-vg1');

    $words = hbRender(hbSet([], 2, [1 => hbWords()]));

    expect(substr_count($words, '<div class="hb-pos'))->toBe(1)
        ->and($words)->toContain('--hb-vg1-d:1;--hb-vb-d:0px;--hb-vg2-d:0;--hb-xp-d:0;--hb-xo-d:0px');
});

it('moves the phone\'s arrows out of the way when the full-width box is not at the bottom', function () {
    /*
     * THE DEFECT, measured at 390: with the box at the Top, the left arrow sat
     * over the left end of the button, so a tap there hit the arrow. At the
     * bottom (the shipped place) the arrows stay at 34%, as in 2.60.432.
     * MUTATION: always return '' for hb-na-m and the first row is red.
     */
    expect(BannerTextBox::rootClasses(BannerTextBox::normalize(['vpos_m' => 'top'])))->toContain('hb-na-m')
        ->and(BannerTextBox::rootClasses(BannerTextBox::normalize(['vpos_m' => 'custom', 'vval_m' => 40, 'vunit_m' => 'px'])))->toContain('hb-na-m')
        ->and(BannerTextBox::rootClasses(BannerTextBox::normalize([])))->not->toContain('hb-na-m')
        ->and(BannerTextBox::rootClasses(BannerTextBox::normalize(['style' => 'd'])))->not->toContain('hb-na-m')
        ->and(hbCss())->toMatch('/@media \(max-width:767\.98px\)\{[^@]*\.kbbs\.has-hb\.hb-na-m \.kbbs-nav\{display:none\}/');
});
