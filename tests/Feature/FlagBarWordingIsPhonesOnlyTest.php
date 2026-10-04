<?php

declare(strict_types=1);

/**
 * THE WORDS COME OFF THE DESKTOP STRIP, AND ONLY OFF THE DESKTOP. (Lane BG)
 *
 * The owner, whole:
 *
 *   "UAE's Authentic K-Beauty Store
 *    remove this from the desktop version."
 *
 * ── WHAT THE SHOP LOOKED LIKE BEFORE ────────────────────────────────────────
 *
 * The thin pink strip carried `[UAE flag] UAE's Authentic K-Beauty Store
 * [Korean flag]` at every width. Measured in Chromium
 * (docs/flagbar-shots/before-measurements.json): at 1920 the two flags sat at
 * x=142 and x=1757 with the pill between them; at 390, x=22 and x=347.
 *
 * ── HE QUOTED THE LINE, NOT THE BAR ─────────────────────────────────────────
 *
 * So the bar stays and the words go: on desktop the strip is the two flags,
 * centred; on phones nothing changes. `fb_desktop` is NOT touched — turning
 * that off would take the flags with it, and 2.60.330 moved it from off to on
 * because he said "apply this on desktop and mobile both". §3 is the case that
 * pins that it stayed put.
 *
 * ── THE ONE THING WORTH READING SLOWLY ──────────────────────────────────────
 *
 * The strip is ONE ELEMENT in ONE DOCUMENT. `flagBarClass()` puts both `kfb-m`
 * and `kfb-d` on it and two media queries decide which width sees it; this shop
 * serves the same bytes to a phone and a desktop, deliberately, because a
 * cached page has to stay correct on every device and because sniffing the user
 * agent is what this codebase refuses to do.
 *
 * So the words CANNOT be left out of the markup on desktop without also leaving
 * them out on phones. The mechanism is therefore a class the desktop media
 * query reads, and §2 asserts the SCOPE rather than taking it on trust: the
 * rule must be inside `@media (min-width:901px)`, which is the same query the
 * strip is already turned on for desktop in. A rule written outside it would
 * pass every other case in this file and take the line off phones too.
 */

use App\Services\HeaderSettings;
use App\Services\SettingsService;

function fbwHome(): string
{
    SettingsService::forgetMemo();

    // ▲ (Lane HC) /shop/ and not /: on the homepage the strip is the
    // `countries` section, shown per device by its Homepage row, while this
    // file is about the Flag bar's own switches — which every other page reads.
    return test()->get('/shop/')->assertOk()->getContent();
}

/** The `<div class="kfb …">` element with everything inside it, or null. */
function fbwElement(string $html): ?string
{
    return preg_match('#<div class="kfb\b.*?</div>\s*</div>#s', $html, $m) === 1 ? $m[0] : null;
}

function fbwCss(): string
{
    return (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));
}

beforeEach(function () {
    SettingsService::forgetMemo();
    /*
     * The strip ships OFF since Lane PI-B ("Turn off the top countries bar
     * entirely for now"). The wording switch this file pins is a property of
     * the strip when it is on, so every case here starts with it on.
     */
    app(HeaderSettings::class)->save(['fb_mobile' => true, 'fb_desktop' => true]);
});

/* ══════════════════ 1. it ships applied ══════════════════ */

it('ships with the wording off on desktop, and says so on the element', function () {
    /*
     * MUTATION NOTE, run: change `fb_text_desktop`'s default in
     * HeaderSettings::SCHEMA from false to true and this goes red on the first
     * expectation and again on `kfb-notx`. Ran, red, put back.
     */
    expect(app(HeaderSettings::class)->all()['fb_text_desktop'])->toBeFalse();

    $el = fbwElement(fbwHome());

    expect($el)->not->toBeNull('no flag bar on the shipped storefront')
        ->and($el)->toContain('kfb-notx')
        // The BAR is untouched: both widths still draw it and both flags are
        // still in it. A green that came from the strip disappearing would be
        // the other reading of his sentence, which is not the one to ship.
        ->and($el)->toContain('kfb-m')
        ->and($el)->toContain('kfb-d')
        ->and(substr_count($el, 'class="kfb-fl"'))->toBe(2);

    // Exactly one strip on the page, and therefore exactly one of the class.
    expect(substr_count(fbwHome(), 'kfb-notx'))->toBe(1);
});

it('keeps the words in the markup, because the same document serves phones', function () {
    /*
     * THE ASSERTION THAT LOOKS BACKWARDS AND IS THE POINT. If the line were
     * removed server-side it would be gone from the phone strip too, which is
     * the half of his instruction that says "desktop". So the words must still
     * be in the document — what changes is whether they are DISPLAYED, and
     * therefore whether they are in the accessibility tree.
     *
     * MUTATION NOTE: make flag-bar.blade.php drop the <span class="kfb-tx">
     * when the setting is off and this goes red — and the phone strip in
     * docs/flagbar-shots/after-home-bar-390.png loses its line, which is the
     * defect the case exists to stop.
     */
    $el = fbwElement(fbwHome());

    /*
     * THE APOSTROPHE IS `&#039;` IN THE DOCUMENT, because Blade's `{{ }}`
     * escapes it — so the obvious literal is a string the page never contains
     * and a test written with it fails for a reason that has nothing to do
     * with the change. Decoded rather than matched raw, which also means this
     * case keeps working if the wording is ever re-escaped differently.
     */
    expect($el)->toContain('class="kfb-tx"');

    expect(html_entity_decode($el, ENT_QUOTES, 'UTF-8'))
        ->toContain("UAE's Authentic K-Beauty Store");
});

it('puts the line back on desktop when the switch is turned on', function () {
    /*
     * The way back, which is what makes this a moved default rather than a
     * deletion. Appearance → Header → Flag bar → Show the wording on desktop.
     *
     * MUTATION NOTE: invert the arms of the `fb_text_desktop` ternary in
     * flagBarClass() and this goes red — `kfb-notx` appears exactly when the
     * owner has asked for the words.
     */
    app(HeaderSettings::class)->save(['fb_text_desktop' => true]);
    SettingsService::forgetMemo();

    $el = fbwElement(fbwHome());

    expect($el)->not->toBeNull()
        ->and($el)->not->toContain('kfb-notx')
        ->and($el)->toContain('kfb-d');
});

/* ══════════════════ 2. and only on desktop ══════════════════ */

it('hides the line inside the desktop media query and nowhere else', function () {
    /*
     * THE SCOPE, ASSERTED RATHER THAN TRUSTED. A `.kfb-notx .kfb-tx{display:
     * none}` written at the top level of the stylesheet would satisfy every
     * case in §1 and take the words off a 390px phone — which is precisely
     * what he did not ask for. The phone rendering is pinned by a picture as
     * well: docs/flagbar-shots/before-home-bar-390.png and after-home-bar-390
     * .png are BYTE-IDENTICAL PNGs.
     *
     * 901px is the line this stylesheet already uses in twenty other rules and
     * is the same query `.kfb-d{display:block}` lives in, so there is one
     * desktop/phone boundary in this block rather than two that could drift.
     *
     * MUTATION NOTE, run: move the two declarations out of the
     * `@media (min-width:901px)` block and this goes red on `inQuery`. Ran —
     * and at 390 the strip then renders as two flags and no words.
     */
    /*
     * COMMENTS STRIPPED FIRST. This stylesheet is more comment than
     * declaration and the paragraph above the rule names `kfb-notx` four
     * times — so "the class appears nowhere outside the query" is false of the
     * file and true of the CSS, and the check has to be of the CSS.
     */
    $css = (string) preg_replace('#/\*.*?\*/#s', '', fbwCss());

    // Every rule in the sheet that mentions the class, with the block it is in.
    preg_match_all('/@media\s*\(min-width:901px\)\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', $css, $queries);

    $inQuery = implode("\n", $queries[1]);

    expect($inQuery)->toContain('.kfb-notx .kfb-tx{display:none}')
        ->and($inQuery)->toContain('.kfb-notx .kfb-in{justify-content:center}');

    // And the class appears NOWHERE outside a min-width:901px block.
    $outside = str_replace($queries[0], '', $css);

    expect($outside)->not->toContain('kfb-notx');

    /*
     * The one rule that would undo it from the other side: nothing may hide
     * `.kfb-tx` unconditionally. Asserted as "the only display:none touching
     * .kfb-tx is the gated one", because a `.kfb-tx{display:none}` anywhere
     * would be the same defect with a different spelling.
     */
    preg_match_all('/[^{}@]*\.kfb-tx[^{}]*\{[^}]*\}/', $css, $txRules);

    foreach ($txRules[0] as $rule) {
        if (str_contains($rule, 'display:none')) {
            expect($rule)->toContain('kfb-notx');
        }
    }
});

/* ══════════════════ 3. and nothing else moved ══════════════════ */

it('leaves the bar itself, its flags and its height exactly where they were', function () {
    /*
     * THE OTHER READING OF HIS SENTENCE, REFUSED. `fb_desktop` off would have
     * removed the whole strip — flags included — when he asked only for the
     * line to come off. This case is the pin that says the wording switch
     * moved nothing but the wording.
     *
     * ▲ `fb_desktop` and `fb_mobile` THEMSELVES ARE NO LONGER PINNED HERE.
     *   (Lane PI-B) He has since asked for the whole bar off "for now", so
     *   both ship false and FlagBarTest's first case pins that. What stays
     *   true is this round's half: with the strip switched on, the desktop
     *   strip is still there (`kfb-d`), with its flags and its height.
     *
     * MUTATION NOTE: make flagBarClass() drop `kfb-d` when `fb_text_desktop`
     * is off — the wrong reading — and this is red on the element.
     */
    $c = app(HeaderSettings::class)->all();

    expect($c['fb_flags'])->toBeTrue('the flags are the half of the bar he kept')
        // The height is reserved in the stylesheet, so the header below the
        // strip cannot jump — taking the words out must not change it.
        // ▲ (Lane HC) 46px: the old shop's countries strip, his screenshot.
        ->and($c['fb_height'])->toBe(46);

    expect(str_contains((string) fbwElement(fbwHome()), 'kfb-d'))->toBeTrue('the desktop strip went with the words');

    // The wording setting itself is untouched: phones still read it, so
    // emptying it would have been the wrong fix.
    expect($c['fb_text'])->toBe('');
});

it('offers the control on the Flag bar tab, next to the wording it qualifies', function () {
    /*
     * Rule 3 — the owner should never have to hunt for what a lane just built.
     * Appearance → Header → Flag bar → "Show the wording on desktop", directly
     * under "Wording".
     *
     * MUTATION NOTE: take the key out of TABS['flagbar'] and this goes red —
     * the setting still works and the screen never shows it, which is the
     * "built, never wired up" shape this repo keeps finding.
     */
    [, , $keys] = HeaderSettings::TABS['flagbar'];

    expect($keys)->toContain('fb_text_desktop');

    $at = array_search('fb_text_desktop', $keys, true);

    expect($keys[$at - 1])->toBe('fb_text');

    // It is a bool the header screen already knows how to draw, so the console
    // needed no edit — hdField() switches on `type` and names no setting.
    expect(HeaderSettings::SCHEMA['fb_text_desktop'][0])->toBe('bool');
});
