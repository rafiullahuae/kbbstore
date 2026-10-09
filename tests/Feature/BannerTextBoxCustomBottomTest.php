<?php

declare(strict_types=1);

use App\Support\BannerTextBox;

/*
 * Lane QK -- "this 100% is not taking the box full bottom, 100% mean full
 * bottom."
 *
 * Appearance -> Banners -> (homepage set) -> Text box -> Position on a phone:
 * he had "Up and down" at Bottom and typed 100 % beside it. Two defects:
 *
 *   1. The number is read only when Up and down is Custom, and the screen let
 *      him type it beside Bottom, where it did nothing.
 *   2. Custom 100% was the same place as Bottom: the box's column ended at the
 *      inset bars' whole 66px strip, 44px of which is only the scrim's padding,
 *      so the box stopped 62px above the bars (measured in Chromium at 390,
 *      style D; 60px at 1280). After: 20px -- the top of the bars' tap strip,
 *      the visible bar 8px under it -- and 0px when no control is on the
 *      picture (tools/qk-banner-bottom.cjs).
 */
require_once __DIR__.'/../Support/BannerTextBoxHelpers.php';

function qkCss(): string
{
    return (string) file_get_contents(resource_path('views/partials/home/slider-text-box-css.blade.php'));
}

it('runs a custom up-and-down to the banner\'s bottom edge, on a phone and on a computer', function () {
    /*
     * MUTATION: drop the hb-vc-* loop from rootClasses(), or either
     * `hb-vc-{m,d}{--hb-bot:var(--hb-room,0px)}` rule, and Custom 100% is
     * Bottom again, 62px above the bars -- red.
     */
    $custom = BannerTextBox::normalize(['vpos_m' => 'custom', 'vval_m' => 100, 'vpos_d' => 'custom', 'vval_d' => 100]);

    expect(BannerTextBox::rootClasses($custom))->toContain(' hb-vc-d')->toContain(' hb-vc-m')
        ->and(BannerTextBox::positionNumbers($custom, 'm'))->toMatchArray(['vg1' => '1', 'vg2' => '0'])
        // Only Custom: Bottom, the style's own and the defaults keep their Auto inset.
        ->and(BannerTextBox::rootClasses(BannerTextBox::normalize(['vpos_m' => 'bottom', 'vval_m' => 100])))->not->toContain('hb-vc-')
        ->and(BannerTextBox::rootClasses(BannerTextBox::normalize([])))->not->toContain('hb-vc-')
        ->and(BannerTextBox::rootClasses(BannerTextBox::normalize(['vpos_m' => 'custom'])))->toContain('hb-vc-m')->not->toContain('hb-vc-d')
        // Beats both the treatment's Auto inset and a "space below" (0,4,0).
        ->and(qkCss())->toContain('  .kbbs.kbbs.kbbs.has-hb.hb-vc-m{--hb-bot:var(--hb-room,0px)}')
        ->and(qkCss())->toContain('  .kbbs.kbbs.kbbs.has-hb.hb-vc-d{--hb-bot:var(--hb-room,0px)}')
        // D is tilted: its low corner lands on the edge, not past it.
        ->and(qkCss())->toContain('.kbbs.kbbs.kbbs.has-hb.hb-d.hb-vc-m{--hb-bot:calc(var(--hb-room,0px) + 1.05cqi)}')
        ->and(qkCss())->toContain('.kbbs.kbbs.kbbs.has-hb.hb-d.hb-vc-d{--hb-bot:calc(var(--hb-room,0px) + var(--hb-w) * .014)}');

    // On the page: the class reaches the slider, as a constant.
    $html = hbRender(hbSet(['text_box' => json_encode(['vpos_m' => 'custom', 'vval_m' => 100])], 1, [1 => hbWords()]));
    expect($html)->toMatch('/class="kbbs [^"]*\bhb-vc-m\b/')->not->toMatch('/class="kbbs [^"]*\bhb-vc-d\b/');
});

it('keeps only the slider\'s own controls uncovered, not the scrim above them', function () {
    /*
     * --hb-room is the top of what takes a tap on the picture's bottom edge:
     * the inset bars' 20px strip, the cornered ticks (16 + 20) or capsule
     * (16 + 36). It was 66px for the inset bars. MUTATION: put
     * `--hb-room:66px` back and Custom 100% stops 62px up again -- red. The
     * Auto insets (66 / 64 / 68px) are untouched: Bottom does not move.
     */
    $css = qkCss();

    expect($css)->toContain('.kbbs.has-hb.is-bars.is-inset{--hb-bot:66px;--hb-bot0:66px;--hb-room:var(--kbbs-hit,20px)}')
        ->and($css)->toContain('.kbbs.has-hb.is-corner.is-bars,.kbbs.has-hb.is-corner.is-arrows{--hb-bot:64px;--hb-bot0:64px}')
        ->and($css)->toContain('.kbbs.has-hb.is-corner.is-bars{--hb-room:calc(16px + var(--kbbs-hit,20px))}')
        ->and($css)->toContain('.kbbs.has-hb.is-corner.is-arrows{--hb-room:calc(16px + var(--kbbs-navh,36px))}')
        ->and($css)->toContain('  .kbbs.has-hb.is-bars.is-inset{--hb-bot:max(66px,4cqi);--hb-bot0:max(66px,4cqi)}')
        ->and($css)->toContain('  .kbbs.has-hb.is-corner.is-bars,.kbbs.has-hb.is-corner.is-arrows{--hb-bot:68px;--hb-bot0:68px}')
        ->and(substr_count($css, '--hb-room:66px'))->toBe(0)
        ->and(substr_count($css, '--hb-room:6'))->toBe(0);
});

it('lets the box\'s button take a click over the inset scrim once the box may sit there', function () {
    /*
     * With the box at 20px its button sat inside .kbbs-bars' 44px scrim
     * padding, and elementFromPoint on the button returned .kbbs-bars: the
     * button did nothing. The strip lets the pointer through; the bars keep
     * theirs. Scoped to Custom and a set "space below", so a banner at Auto
     * behaves exactly as before. MUTATION: drop either rule -- red.
     */
    $scope = '.kbbs.has-hb.is-inset:is(.hb-vc-d,.hb-vc-m,.hb-sb-d,.hb-sb-m)';

    expect(qkCss())->toContain($scope.' .kbbs-bars{pointer-events:none}')
        ->and(qkCss())->toContain($scope.' .kbbs-bar{pointer-events:auto}');
});

it('switches Up and down to Custom when he types the number beside it', function () {
    /*
     * He typed 100 beside "Bottom" and nothing happened. Typing the number or
     * its unit now picks Custom on the same axis and device, and the select
     * shows it. MUTATION: drop the `axis` block from the set handler -- red.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/banners-screen.blade.php'));

    expect($screen)->toContain("var axis = /^tb_([vh])(?:val|unit)_([dm])\$/.exec(key);")
        ->and($screen)->toContain("var mode = 'tb_' + axis[1] + 'pos_' + axis[2];")
        ->and($screen)->toContain("draft.set[mode] = 'custom';")
        ->and($screen)->toContain("document.querySelectorAll('[data-bns-set=\"' + mode + '\"]').forEach(function(o){ o.value = 'custom'; });")
        ->and($screen)->toContain('Custom uses the number beside it; typing that number picks Custom.')
        ->and($screen)->toContain('100 the bottom \u2014 the box\u2019s bottom edge on the banner\u2019s');

    // The keys the regex reads are the ones the screen writes, and Custom is a real option.
    expect($screen)->toContain("var key = 'tb_' + axis + 'val_' + dev, unit = 'tb_' + axis + 'unit_' + dev;")
        ->and(BannerTextBox::VPOS)->toHaveKey('custom')
        ->and(BannerTextBox::HPOS)->toHaveKey('custom');
});
