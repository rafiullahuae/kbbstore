<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Bidi;
use App\Support\Locale;

/**
 * `-30%` READ AS `30%-` ON THE ARABIC SALE RIBBON.
 *
 * docs/rtl-audit.md §11.10 photographed it and said, correctly, that it is not
 * CSS: HYPHEN-MINUS carries the WEAK bidi class ES, so the Unicode
 * bidirectional algorithm resolves it from the run around it and moves a
 * LEADING one to the trailing side of a right-to-left run.
 *
 * ── THE MEASUREMENT THAT PICKED THE FIX, AND THE TWO THINGS IT CORRECTED ────
 *
 * Every candidate was rendered in Chromium and each character's box read back
 * and sorted by x, so what follows is the order the engine actually painted
 * rather than a reading of the specification. Logical string `-30%`:
 *
 *                            <html dir=rtl>            <html dir=ltr>
 *                            alone   in Arabic text    alone   in Arabic text
 *   plain HYPHEN-MINUS       30%-    %30-              -30%    %30-
 *   U+2212 MINUS SIGN        30%−    %30−              −30%    %30−
 *   CSS unicode-bidi:isolate 30%-    30%-              -30%    -30%
 *   U+2066 … U+2069          -30%    -30%              -30%    -30%
 *
 *   1. §11.10 OFFERS U+2212 AS AN ALTERNATIVE TO THE ISOLATE. IT IS NOT ONE.
 *      U+2212 is bidi class ES exactly as HYPHEN-MINUS is; it reorders
 *      identically and only its shape differs. The audit's sentence is wrong and
 *      this test is what stops somebody acting on it.
 *   2. THE DEFECT IS NOT CONFINED TO THE MIRRORED LAYOUT. In an
 *      `<html dir="ltr">` document, `-30%` inside Arabic text still paints
 *      `%30-`, because an Arabic word opens a right-to-left run wherever it
 *      stands. So the isolate is gated on the LANGUAGE, never on
 *      Locale::isRtl() — the same conclusion the Arabic typeface reached, for a
 *      different reason.
 *
 * ── AND WHY THE ENGLISH PAGE GETS NOTHING ───────────────────────────────────
 *
 * Measured in the same run: in an English document, `-30%` already paints
 * `-30%`, isolate or no isolate. Adding it there would move the bytes of every
 * English storefront page — StorefrontEnglishUnchangedTest's contract — for no
 * rendered difference at all.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

function fsBidiState(bool $arabic, bool $mirrored = true): void
{
    $s = app(SettingsService::class);
    $s->set(Locale::SETTING_ENABLED, $arabic ? '1' : '0');
    $s->set(Locale::SETTING_RTL, $mirrored ? '1' : '0');
    $s->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
}

function fsBidiSaleProduct(): Product
{
    return Product::create([
        'name' => 'Bidi Ribbon Serum',
        'slug' => 'bidi-ribbon-serum',
        'sku' => 'BIDI-0001',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'sale_price' => 7000,        // a clean 30% off, so the ribbon reads -30%
        'stock_status' => 'instock',
        'image' => 'https://cdn.test/bidi.jpg',
    ]);
}

it('wraps the sale ribbon percentage in an LTR isolate on an Arabic page', function () {
    fsBidiSaleProduct();
    fsBidiState(arabic: true);

    // The product page's gallery badge — partials/product-gallery.blade.php — is
    // the ribbon this lane owns. The product page ALSO carries store/product's
    // own `.off` ribbon, which belongs to Lane FP and is handed off with an
    // anchor and a replacement in docs/FS-ARABIC-TYPOGRAPHY.md; the sweep below
    // is what keeps that hand-off honest.
    $html = test()->get('/ar/product/bidi-ribbon-serum/')->assertOk()->getContent();

    // The page has to actually carry a ribbon, or everything below is a claim
    // about a page with no discount on it.
    expect($html)->toContain('30%');
    expect($html)->toContain(Bidi::LRI . '-30%' . Bidi::PDI);
});

it('leaves the English page without a single isolate character', function () {
    fsBidiSaleProduct();

    // Arabic switched ON, English URL. The gate is the page's language, and the
    // English bytes are a contract another test pins to a commit.
    fsBidiState(arabic: true);

    foreach (['/shop/', '/', '/product/bidi-ribbon-serum/'] as $url) {
        $html = test()->get($url)->assertOk()->getContent();

        expect(str_contains($html, Bidi::LRI))->toBeFalse("{$url} carries U+2066 on an English page.");
        expect(str_contains($html, Bidi::PDI))->toBeFalse("{$url} carries U+2069 on an English page.");
    }

    // The bare ribbon is what English is SUPPOSED to have, unchanged — so this
    // is not a test that passes because nothing prints a sign at all.
    expect(test()->get('/product/bidi-ribbon-serum/')->getContent())->toContain('>-30%<');
});

it('isolates every signed number the rendered Arabic storefront prints, not only the ribbon', function () {
    fsBidiSaleProduct();
    fsBidiState(arabic: true);

    /*
     * A RENDERED sweep rather than a grep over the views, because the shape
     * being looked for — a sign immediately in front of a digit, in text a
     * shopper reads — is a property of the OUTPUT. A regex over a Blade file
     * reads its own comments as code, and several of these views discuss the
     * defect in prose.
     *
     * Only text nodes are looked at: attribute values, inline CSS and inline
     * JavaScript are full of `-1`, `+2` and `translateX(-100%)`, none of which
     * the bidi algorithm ever paints.
     */
    /*
     * TWO SITES ARE KNOWN AND HANDED OFF rather than silently tolerated. Both
     * are in views this lane may not edit — resources/views/components/
     * product-card.blade.php and resources/views/store/product.blade.php belong
     * to Lane FP — and docs/FS-ARABIC-TYPOGRAPHY.md carries the exact anchor and
     * replacement for each. They are listed by the text they print so that a
     * THIRD one cannot hide behind them, and the test additionally fails if a
     * listed one stops appearing, which is how this list gets deleted rather
     * than outliving the defect.
     */
    /*
     * INTEGRATOR: `-30%` IS GONE FROM THIS LIST BECAUSE IT WAS FIXED, and the
     * staleness check above is what told me to delete it rather than leave it
     * covering something else. Lane FS's two blocks for the .off and .qv-off
     * ribbons are applied; both now print an isolated number and the sweep
     * sees them as clean.
     *
     * `-30% OFF` STAYS, and not because it is unfixed. The card's whole label
     * is one translatable string, so it is wrapped in <bdi> rather than given
     * an isolate — and <bdi> is MARKUP, which puts nothing into the text node
     * this sweep reads. So the text still leads with a sign and this sweep will
     * still see it. The entry records that, so the next person does not "fix"
     * a badge that is already right and end up with an isolate forcing an
     * Arabic label left-to-right.
     */
    /*
     * KEYED ON THE SHAPE, NOT ON THE NUMBER, and that correction was forced
     * rather than chosen. This entry read `-30% OFF` — a literal that held only
     * while a seeded demo product happened to be discounted by exactly thirty
     * per cent. Lane FV's fix to DemoCatalogueSeeder (rounding a demo sale
     * price down to a whole dirham, because 70% of a whole dirham is not one)
     * moved that product to 31% off, the literal stopped matching, and this
     * sweep reported the product card as a NEW offender on a change that had
     * nothing to do with bidi.
     *
     * The percentage is fixture data. What identifies this site is the badge's
     * shape — a sign, a number, a per-cent sign and the word OFF — so that is
     * what the key is now, as a pattern. It stays exactly as narrow: it still
     * names one badge in one view, and a second unisolated node anywhere on
     * these three pages still fails, which is the property this list exists for.
     */
    $handedOff = [
        '/^-\d+% OFF$/' => 'components/product-card.blade.php — handled by <bdi>, which this text-node sweep cannot see',
    ];

    $offenders = [];
    $seenHandedOff = [];
    $scanned = 0;

    foreach (['/ar/shop/', '/ar/', '/ar/product/bidi-ribbon-serum/'] as $url) {
        $html = test()->get($url)->assertOk()->getContent();

        $text = preg_replace(['#<script\b.*?</script>#is', '#<style\b.*?</style>#is'], '', $html);

        // Every text node, with the tags removed but the isolate characters kept.
        foreach (preg_split('/<[^>]*>/', (string) $text) as $node) {
            $node = trim(html_entity_decode($node, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($node === '') {
                continue;
            }

            $scanned++;

            if (str_contains($node, Bidi::LRI)) {
                continue;
            }

            /*
             * A SIGN, not a range dash. Two shapes reorder and nothing else
             * does: a sign that opens the text node (`– AED 25.00` on the cart's
             * discount row), and a sign welded to a digit at the start of a word
             * (`-30%`, `+971`). `AED 54 – 150` and `1–3 days` are neither — the
             * dash sits between two numbers inside one left-to-right run — and a
             * sweep that flagged them would be teaching people to ignore it.
             */
            $leading = preg_match('/^[-+\x{2212}\x{2013}]\s?\d/u', $node) === 1
                || preg_match('/(?:^|\s)[-+\x{2212}]\d/u', $node) === 1;

            if (! $leading) {
                continue;
            }

            foreach ($handedOff as $known => $owner) {
                if (preg_match($known, $node) === 1) {
                    $seenHandedOff[$known] = true;

                    continue 2;
                }
            }

            $offenders[] = sprintf('%s: %s', $url, mb_substr($node, 0, 80));
        }
    }

    expect($scanned)->toBeGreaterThan(50, 'The Arabic pages produced almost no text to scan; this sweep is vacuous.');
    expect($offenders)->toBe([], "These rendered Arabic text nodes lead with a sign the bidi algorithm will move to the other end:\n" . implode("\n", $offenders));

    $stale = array_diff(array_keys($handedOff), array_keys($seenHandedOff));

    expect($stale)->toBe([], implode("\n", array_map(
        static fn (string $k): string => "`{$k}` is no longer printed unisolated — {$handedOff[$k]} has been fixed, so delete this exception rather than leaving it to cover something else.",
        $stale
    )));
});

it('is a no-op in English, wraps in Arabic and never wraps twice', function () {
    fsBidiState(arabic: false);
    expect(Bidi::number('-30%'))->toBe('-30%');
    expect(Bidi::number('+2'))->toBe('+2');

    fsBidiState(arabic: true);

    // Locale::current() reads app()->getLocale() and is deliberately NOT
    // memoised, so setting the application locale is all a unit context needs.
    app()->setLocale('ar');
    expect(Locale::current())->toBe('ar');

    expect(Bidi::number('-30%'))->toBe(Bidi::LRI . '-30%' . Bidi::PDI);
    expect(Bidi::number(''))->toBe('');

    // Idempotent, so a value that passes through two formatters is wrapped once.
    $once = Bidi::number('-30%');
    expect(Bidi::number($once))->toBe($once);
});
