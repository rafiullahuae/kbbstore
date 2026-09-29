<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;

/**
 * The Arabic typeface — that it is USED, not merely downloaded.
 *
 * BilingualFoundationTest already pins the <link>: `family=Cairo` on an Arabic
 * page, absent on an English one. That test passed from the day it was written
 * and the shop still rendered every Arabic word in the device fallback, because
 * a <link> is not a font stack. No stylesheet in this storefront named Cairo:
 * `--sans` is "Poppins",system-ui,… in all four files that define it, kbb.css
 * sets `body{font:400 14px/1.6 Poppins,system-ui,sans-serif}` with the
 * shorthand, and the product card name, the badges and the add-to-cart button
 * hard-code 'Poppins',sans-serif. A browser fetches a face when something uses
 * it; nothing did.
 *
 * MEASURED in Chromium with Cairo served from Google's own bytes, the same
 * Arabic string at 40px, in the page's inherited stack versus in Cairo:
 *
 *              stack     Cairo
 *   wght 400   496.53    479.05     before — matches neither, i.e. the fallback
 *   wght 800   595.63    537.88     before
 *   wght 400   479.05    479.05     after
 *   wght 800   537.88    537.88     after
 *
 * So this file pins the three things that make it render, each of which was
 * separately wrong or separately easy to undo:
 *
 *   1. Cairo is NAMED in the stacks, on Arabic pages only;
 *   2. it is APPENDED after the Latin face and never substituted for it, so
 *      Latin still renders in the brand face by per-codepoint selection;
 *   3. the request still carries weight 800, which 79 declarations use and
 *      which costs no font bytes — the same variable WOFF2 serves all four.
 *
 * And the whole thing is gated on the LANGUAGE, not on Locale::isRtl(): with
 * RTL switched off, Arabic is still Arabic and still needs Arabic glyphs.
 */
function flArabicOn(bool $rtl = true): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => $rtl ? '1' : '0', 'autoload' => true]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}


/**
 * The document with every `@font-face` block removed.                Lane PERF
 *
 * ── WHY THIS IS NOT TIDINESS ────────────────────────────────────────────────
 *
 * The three cases below scan for `font-family:` and check what comes FIRST in
 * what they find, because a stack that puts Cairo first substitutes the brand
 * face instead of backing it up. Since Cairo is served by this shop rather than
 * by Google, the page also carries twelve `@font-face{font-family:'Cairo';…}`
 * rules — and a `@font-face` DECLARES a family, it does not choose between
 * families. Scanned as a stack, every one of them reads "Cairo is first" and
 * the test reports a substitution that is not there.
 *
 * This is the same shape of fault CardsBannerSectionShapeTest::bnsMarkup()
 * exists for: "an absence assertion has to be made against the MARKUP, not
 * against the markup plus a document that mentions every class by name".
 */
function flStacksOnly(string $html): string
{
    return (string) preg_replace('/@font-face\s*\{[^}]*\}/i', '', $html);
}

it('names Cairo in the font stack on an Arabic page, not just in a <link>', function () {
    flArabicOn();

    $html = test()->get('/ar/my-wishlist/')->assertOk()->getContent();

    /* WAS `family=Cairo`, WHICH WAS A GOOGLE FONTS URL — Lane PERF. Cairo is
       served by this shop now (App\Support\WebFonts carries the measurement),
       so what has to be true is unchanged and only its spelling moved: the page
       declares the face and tells the browser to fetch it early. A preload for
       a file the page never names in a @font-face is the same "never fetched"
       failure this assertion was written for, so both halves are checked. */
    expect(str_contains($html, "@font-face{font-family:'Cairo'"))->toBeTrue(
        'The face declaration is gone; the rest of this test would pass against a face that is never fetched.');
    expect($html)->toContain('rel="preload" as="font"');
    expect($html)->toMatch('#href="[^"]*cairo-arabic-[^"]*\.woff2"#');

    // At least one real font stack has to say Cairo, or the download is wasted
    // and every Arabic word renders in the device fallback.
    $stacksOnly = flStacksOnly($html);

    preg_match_all('/font-family\s*:\s*([^;}]*Cairo[^;}]*)/i', $stacksOnly, $stacks);
    preg_match_all('/--sans\s*:\s*([^;}]*Cairo[^;}]*)/i', $stacksOnly, $tokens);

    $named = array_merge($stacks[1], $tokens[1]);

    expect($named)->not->toBeEmpty(
        "An Arabic page links Cairo and then never asks for it. No font-family or --sans on the page mentions Cairo, so Poppins — which has no Arabic glyphs — falls through to the system fallback, which is the exact defect the <link> was added to fix.");
});

it('appends Cairo after the Latin face instead of substituting for it', function () {
    flArabicOn();

    $html = test()->get('/ar/my-wishlist/')->getContent();

    preg_match_all('/(?:font-family|--sans)\s*:\s*([^;}]*Cairo[^;}]*)/i', flStacksOnly($html), $m);

    // Quotes are NOT excluded from that character class on purpose: every stack
    // on this page quotes at least one family name, and a class that stopped at
    // the first quote would find nothing and pass against anything.
    expect($m[1])->not->toBeEmpty('No Cairo-bearing font stack was found to check the order of.');

    $wrong = [];

    foreach ($m[1] as $stack) {
        $families = array_map(
            static fn (string $f): string => strtolower(trim($f, " \t\"'")),
            explode(',', $stack)
        );
        $cairo = array_search('cairo', $families, true);

        // Something Latin has to come first. Cairo's Latin is not the brand
        // face, and per-codepoint selection is the whole point: Poppins for the
        // wordmark and the prices, Cairo only for codepoints Poppins lacks.
        if ($cairo === 0) {
            $wrong[] = "Cairo is first in `{$stack}` — that substitutes the brand face rather than backing it up.";
        }
    }

    expect($wrong)->toBe([], implode("\n", $wrong));
});

it('keeps the English page free of the Arabic face entirely', function () {
    flArabicOn();

    $html = test()->get('/my-wishlist/')->assertOk()->getContent();

    expect($html)->not->toContain('family=Cairo')
        ->and($html)->not->toContain('Cairo')
        ->and($html)->not->toContain('kbb-arabic-face');
});

it('asks for weight 800, which the storefront uses and which costs no font bytes', function () {
    flArabicOn();

    $html = test()->get('/ar/my-wishlist/')->getContent();

    // Google serves ONE variable WOFF2 for 400;600;700 and for 400;600;700;800
    // — same URL, same 30,896 bytes, verified by fetching both. Without 800 the
    // wordmark, the checkout h1 and the order total all drop a weight step on
    // an Arabic page while the English page keeps it.
    /* WAS a `family=Cairo:wght@…` query string — Lane PERF. The weights are
       twelve @font-face rules now rather than a list in a URL, and the thing
       that must not be lost is the same: 79 declarations in the storefront set
       text at 800, and Cairo's four weights are ONE variable file per subset,
       so 800 costs no font bytes at all. */
    preg_match_all("/@font-face\{font-family:'Cairo';font-style:normal;font-weight:(\d+);/", $html, $m);

    expect($m[1])->not->toBeEmpty('The Cairo request no longer names its weights.');
    expect(in_array('800', $m[1], true))->toBeTrue(
        'Weight 800 has been dropped from the Cairo request. 79 declarations in the storefront style text at font-weight:800, and it costs no font bytes: the same variable WOFF2 serves every weight in the list.');
});

it('loads the Arabic face with RTL switched off as well', function () {
    // "Arabic on, RTL off" is a state the owner can choose and the Translation
    // console warns him about. It is Arabic words in a left-to-right layout,
    // and Arabic words still need Arabic glyphs. Gating the face on
    // Locale::isRtl() instead of on the language would break exactly this.
    flArabicOn(rtl: false);

    $html = test()->get('/ar/my-wishlist/')->assertOk()->getContent();

    expect(str_contains($html, 'dir="ltr"'))->toBeTrue(
        'This test is meant to run with the mirrored layout OFF; if dir is rtl the setting did not apply.');
    expect($html)->toContain("@font-face{font-family:'Cairo'")->and($html)->toContain('Cairo');
});
