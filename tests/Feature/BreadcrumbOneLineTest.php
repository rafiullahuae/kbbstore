<?php

declare(strict_types=1);

use App\Services\HeaderSettings;
use App\Services\SettingsService;

/**
 * APPEARANCE → HEADER → BREADCRUMBS: one line, its type, and no tall rows.
 *                                                                (Lane CRUMB)
 *
 * The owner, 12 October, over a phone screenshot of a product page whose trail
 * "Home / Super Sale / Anua - PDRN + Hyaluron Ultimate Glass Skin Set" ran onto
 * a second line far below the first: "in mobile the breadcrum line height is
 * too much ... font size, line height etc. also i want that the breadcrums must
 * load in one line only, and if it's long, then it should cut faded from the
 * right side, or put ..."
 *
 * What each case would have caught, on the shop:
 *
 *   - the tall rows: kbb.css's phone tap-target block gave every `.crumb a` a
 *     44px min-height, so a wrapped trail stood 44px a line (measured in
 *     Chromium at 390: 81px before, 37px after, one line);
 *   - a long trail that still wrapped, or that pushed the page sideways;
 *   - the fade on the wrong side in Arabic, where the trail ends on the left;
 *   - a font size or line spacing changing pages he did not mention (they
 *     draw the trail at 12, 12.5 and 13px — `auto` must print nothing);
 *   - a value that is not one of the select's own options reaching <style>;
 *   - a hidden trail printing anything new.
 */

function bolSet(array $values): string
{
    app(HeaderSettings::class)->save($values);
    SettingsService::forgetMemo();

    return app(HeaderSettings::class)->breadcrumbCss();
}

const BOL_SEL = ':root body :is(.crumb,.brw-crumb,.rtn-crumb)';

it('ships one line that fades at the end, on both widths, and takes back the 44px link rows', function () {
    // MUTATION: ship `bc_line_mobile` as `wrap`, or drop the `a{…min-height:0…}`
    // rule from breadcrumbCss(), and this is red.
    $css = bolSet(['bc_mobile' => true, 'bc_desktop' => true]);

    foreach (['(max-width:900px)', '(min-width:901px)'] as $query) {
        $block = substr($css, strpos($css, '@media '.$query));

        expect($block)->toContain(BOL_SEL.'{white-space:nowrap;overflow:hidden;-webkit-mask-image:linear-gradient(90deg,#000 calc(100% - 36px),transparent)')
            ->toContain(BOL_SEL.' a{display:inline;min-height:0;padding-top:0;padding-bottom:0}');
    }

    expect(HeaderSettings::SCHEMA['bc_line_mobile'][2])->toBe('fade')
        ->and(HeaderSettings::SCHEMA['bc_line'][2])->toBe('fade');
});

it('fades the left end in Arabic, where the trail finishes', function () {
    $css = bolSet(['bc_mobile' => true, 'bc_desktop' => false]);

    expect($css)->toContain(':root[dir=rtl] body :is(.crumb,.brw-crumb,.rtn-crumb){-webkit-mask-image:linear-gradient(270deg,#000 calc(100% - 36px),transparent)');
});

it('ends with “…” or wraps as before when he chooses', function () {
    $css = bolSet(['bc_mobile' => true, 'bc_desktop' => true, 'bc_line_mobile' => 'dots', 'bc_line' => 'wrap']);
    [$phone, $desk] = explode('@media (min-width:901px)', $css);

    expect($phone)->toContain('white-space:nowrap;overflow:hidden;text-overflow:ellipsis')->not->toContain('mask-image')
        ->and($desk)->not->toContain('white-space:nowrap')->not->toContain('mask-image')
        // Wrap still loses the 44px rows: "line height is too much" holds either way.
        ->and($desk)->toContain(BOL_SEL.' a{display:inline;min-height:0;padding-top:0;padding-bottom:0}');
});

it('leaves every page its own size and spacing until he picks a number', function () {
    // MUTATION: default `bc_size_mobile` to '125' and the font-size assertion is red.
    $css = bolSet(['bc_mobile' => true, 'bc_desktop' => true]);

    expect($css)->not->toContain('font-size')->not->toContain('line-height');

    $css = bolSet(['bc_size_mobile' => '115', 'bc_lh_mobile' => '130', 'bc_size' => '140', 'bc_lh' => '200']);
    [$phone, $desk] = explode('@media (min-width:901px)', $css);

    expect($phone)->toContain('font-size:11.5px;line-height:1.3;')
        ->and($desk)->toContain('font-size:14px;line-height:2;');
});

it('prints only its own options into the stylesheet', function () {
    $css = bolSet([
        'bc_mobile' => true, 'bc_desktop' => true,
        'bc_size_mobile' => '99}body{display:none', 'bc_lh' => 'url(x)', 'bc_line_mobile' => 'expression(1)',
    ]);
    $saved = app(HeaderSettings::class)->all();

    expect($saved['bc_size_mobile'])->toBe('auto')
        ->and($saved['bc_lh'])->toBe('auto')
        ->and($saved['bc_line_mobile'])->toBe('fade')
        ->and($css)->not->toContain('99}')->not->toContain('url(')->not->toContain('expression');
});

it('prints nothing new while the trail is hidden', function () {
    $css = bolSet(['bc_mobile' => false, 'bc_desktop' => false, 'bc_size_mobile' => '160', 'bc_line_mobile' => 'dots']);

    expect($css)->not->toContain('nowrap')->not->toContain('font-size')->not->toContain('min-height:0');
});

it('puts the six new controls on the Breadcrumbs tab, phone and desktop', function () {
    $keys = HeaderSettings::TABS['crumbs'][2];

    foreach (['bc_line_mobile', 'bc_size_mobile', 'bc_lh_mobile', 'bc_line', 'bc_size', 'bc_lh'] as $key) {
        expect($keys)->toContain($key)
            ->and(HeaderSettings::SCHEMA[$key][0])->toBe('select');
    }
});
