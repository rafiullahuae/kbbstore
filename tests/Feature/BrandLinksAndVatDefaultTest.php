<?php

declare(strict_types=1);

use App\Services\ProductSections;

/**
 * THE BRAND NAME GOES SOMEWHERE, AND THE VAT LINE SHIPS OFF.
 *                                                         (2.60.336)
 *
 * Both came from the owner marking his own product page: an arrow at the brand
 * eyebrow, and a cross through "Inclusive of 5% VAT".
 *
 * ── WHAT THEY LOOKED LIKE ON THE SHOP ────────────────────────────────────
 *
 * 1. THE BRAND NAME WAS A BARE <div>. `/brands/{slug}/` is a real page with
 *    that brand's own copy, logo and product grid, it is in the sitemap, and
 *    `Brand::url()` had existed all along -- the pdp-preview template
 *    `parts/facts.blade.php` was already using it. The LIVE product page was
 *    the one place that named a brand and linked nowhere.
 *
 * 2. THE VAT LINE SHIPPED ON. It now ships off, and the migration removes a
 *    saved choice so an existing shop follows the new default.
 *
 * ── MUTATION NOTES, run and confirmed red ────────────────────────────────
 *
 *   Put the brand back to a bare <div>                       -> case 1 red
 *   Drop the `@if ($product->brand?->url())` guard           -> case 2 red
 *   Change the REGISTRY default back to true                 -> case 3 red
 *   Make the migration write false instead of unsetting      -> case 4 red
 *   Delete the settings-cache forget from the migration      -> case 5 red
 */
it('links the brand name to the brand page', function () {
    $tpl = file_get_contents(base_path('resources/views/store/product.blade.php'));

    // Comments are stripped first. This repository has twice had an assertion
    // satisfied by its own prose describing the defect.
    $tpl = preg_replace('/\{\{--.*?--\}\}/s', '', $tpl);

    expect($tpl)->toContain('<a href="{{ $product->brand->url() }}">{{ $brand }}</a>');

    // The eyebrow's own element must survive: `.bb-brand` carries the size,
    // letter-spacing and the spacing above the name, and `#bbBrand` is read
    // elsewhere. Swapping the tag would move the line.
    expect($tpl)->toContain('<div class="bb-brand" id="bbBrand">');
})->group('pdp');

it('still prints the brand when there is nowhere to link to', function () {
    $tpl = preg_replace('/\{\{--.*?--\}\}/s', '',
        file_get_contents(base_path('resources/views/store/product.blade.php')));

    // A brand with no slug yields no url, and a link to nowhere is worse than
    // plain text -- so the name prints unlinked rather than vanishing.
    expect($tpl)->toContain('@if ($product->brand?->url())');
    expect($tpl)->toContain('@else{{ $brand }}@endif');
})->group('pdp');

it('ships the VAT line off', function () {
    // Read through the registry rather than the file, so a default moved by
    // any route is caught.
    [$label, $desc, $default] = ProductSections::REGISTRY['vat'];

    expect($label)->toBe('VAT line');
    expect($default)->toBeFalse();

    // And the sections it did NOT touch are untouched -- rule 1. A sweep that
    // only checked `vat` would be green on a release that turned everything off.
    expect(ProductSections::REGISTRY['short'][2])->toBeTrue();
    expect(ProductSections::REGISTRY['rating'][2])->toBeTrue();
    expect(ProductSections::REGISTRY['reviews'][2])->toBeTrue();
})->group('pdp');

it('removes a saved VAT choice rather than pinning it false', function () {
    $m = file_get_contents(base_path(
        'database/migrations/2027_06_18_000000_vat_line_off_and_brand_links.php'));
    $m = preg_replace('#/\*.*?\*/#s', '', $m);

    // `all()` reads `$row['desktop'] ?? $default`, so a STORED value wins for
    // ever. Writing false would freeze the row against every future default --
    // the exact harm found on Product styles this week, where a save wrote nine
    // keys at their current values.
    expect($m)->toContain("unset(\$saved['vat'])");
    expect($m)->not->toContain("'vat' => ['desktop' => false");
})->group('pdp');

it('drops the settings cache the migration has just invalidated', function () {
    $m = file_get_contents(base_path(
        'database/migrations/2027_06_18_000000_vat_line_off_and_brand_links.php'));
    $m = preg_replace('#/\*.*?\*/#s', '', $m);

    // The literal key SettingsService actually writes. The first draft of the
    // migration invented 'kbb.settings.all', which this application has never
    // written: forget() on a key that does not exist throws nothing and clears
    // nothing, so it reads as done and leaves the stale snapshot answering with
    // the entry that was just removed.
    expect($m)->toContain("Cache::forget('kbb.settings')");

    $svc = file_get_contents(base_path('app/Services/SettingsService.php'));
    expect($svc)->toContain("private const CACHE_KEY = 'kbb.settings';");
})->group('pdp');
