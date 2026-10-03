<?php

declare(strict_types=1);

/**
 * Buy these together: a tap on the picture never shows the phone's own
 * checkbox, and every tap is a tap.                              (2.60.368)
 *
 * THE DEFECT ON THE LIVE SHOP (owner's iPhone, 3 October 2026): "the buy
 * together selection is giving a weird giant tick icon upon click, and it's
 * not selecting and un-selecing. it works rarely". The picture is covered by
 * its real checkbox at opacity 0. The C · Ripple tap feedback dims every
 * pressed control it does not list to opacity .72 (kbb.css,
 * `html[data-press] .kbb-pressed:not(:is(…))`), so while a finger was down the
 * checkbox showed at 72% with appearance:auto — the phone's own control, the
 * size of the picture: a blue square with a white tick. Measured on the
 * preview at 390 (Chromium): opacity 0.72 before, 0 after; 20 taps, 20 flips.
 *
 * MUTATION NOTES, RUN:
 *   · delete `html[data-press] .kbb-fbt .bt-cb.kbb-pressed{opacity:0}` → RED.
 *   · drop `appearance:none` from .bt-cb → RED.
 */

function bttRules(string $file): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/'.$file)));
}

it('never lets the tap feedback show the picture-sized checkbox', function () {
    $css = bttRules('kbb-product.css');

    expect($css)->toContain('html[data-press] .kbb-fbt .bt-cb.kbb-pressed{opacity:0}')
        ->and($css)->toMatch('/\.bt-cb\{[^}]*-webkit-appearance:none;appearance:none;touch-action:manipulation\}/');

    // The generic dimming rule this outranks is still there, unchanged, for
    // every other control: the fix is local to this section.
    expect(bttRules('kbb.css'))->toContain('html[data-press] .kbb-pressed:not(:is(');

    // And the press script still treats the checkbox as a control (the
    // section's ring changes on `change`, not on the press).
    expect((string) file_get_contents(resource_path('js/kbb/press.js')))->toContain('input[type="checkbox"]');
});

it('ships the rule in the built stylesheet the shop serves', function () {
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $entry = collect($manifest)->first(fn ($e) => str_ends_with((string) ($e['src'] ?? ''), 'kbb-product.css'));
    expect($entry)->not->toBeNull();

    $built = (string) file_get_contents(public_path('build/'.$entry['file']));
    expect($built)->toContain('.kbb-fbt .bt-cb.kbb-pressed{opacity:0}')
        ->and($built)->toContain('touch-action:manipulation');
});
