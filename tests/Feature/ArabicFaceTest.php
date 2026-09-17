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

it('names Cairo in the font stack on an Arabic page, not just in a <link>', function () {
    flArabicOn();

    $html = test()->get('/ar/my-wishlist/')->assertOk()->getContent();

    expect(str_contains($html, 'family=Cairo'))->toBeTrue(
        'The stylesheet link is gone; the rest of this test would pass against a face that is never fetched.');

    // At least one real font stack has to say Cairo, or the download is wasted
    // and every Arabic word renders in the device fallback.
    preg_match_all('/font-family\s*:\s*([^;}]*Cairo[^;}]*)/i', $html, $stacks);
    preg_match_all('/--sans\s*:\s*([^;}]*Cairo[^;}]*)/i', $html, $tokens);

    $named = array_merge($stacks[1], $tokens[1]);

    expect($named)->not->toBeEmpty(
        "An Arabic page links Cairo and then never asks for it. No font-family or --sans on the page mentions Cairo, so Poppins — which has no Arabic glyphs — falls through to the system fallback, which is the exact defect the <link> was added to fix.");
});

it('appends Cairo after the Latin face instead of substituting for it', function () {
    flArabicOn();

    $html = test()->get('/ar/my-wishlist/')->getContent();

    preg_match_all('/(?:font-family|--sans)\s*:\s*([^;}]*Cairo[^;}]*)/i', $html, $m);

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
    preg_match('/family=Cairo:wght@([0-9;]+)/', $html, $m);

    expect($m)->not->toBeEmpty('The Cairo request no longer names its weights.');
    expect(in_array('800', explode(';', $m[1]), true))->toBeTrue(
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
    expect($html)->toContain('family=Cairo')->and($html)->toContain('Cairo');
});
