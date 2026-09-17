<?php

declare(strict_types=1);

/**
 * Lane DZ — the sentence under the price, and the claim that was stuck to it.
 *
 * ── WHAT THIS FILE IS ABOUT ─────────────────────────────────────────────────
 *
 * Store\ProductController::vatLine() printed, on every product page:
 *
 *     "Inclusive of {rate}% VAT · Authentic, sourced direct"
 *
 * Two independent untruths waiting for the owner to use a feature he asked for
 * by name.
 *
 * THE BASIS WAS A LITERAL. "Inclusive of" was typed into the string. It was
 * printed whatever `vat_basis` held, and the rate came from `vat_rate` — the
 * DEFAULT rate — so `vat_country_rates` and `vat_country_bases` were both
 * ignored outright. Set Saudi Arabia to 15% exclusive, as the owner asked
 * ("for uae the vat i can set inclusive, for Saudi i can set exclusive"), and
 * every product page told a Riyadh shopper that 5% tax was already inside a
 * price the checkout was about to add 15% to. No error, no warning, no way to
 * notice except by reading the page.
 *
 * THE TRUST CLAIM HAD NO HOME. 2.60.193 gave every claim about this business
 * one owner-editable box (App\Support\TrustClaims), where clearing the box
 * removes the claim and its element. "Authentic, sourced direct" was welded to
 * a tax line on the one page whose trust row ALREADY prints the authenticity
 * claim through `product_authentic_text`. So the removal half did not work
 * here: clear the claim and the chip goes, while the same claim keeps printing
 * under the price out of a string only a signed zip can reach.
 *
 * ── WHAT IS PINNED, AND WHY IT IS PINNED HERE AND NOT IN VatDisplay ─────────
 *
 * Both halves are asserted against the RENDERED PAGE rather than against the
 * support class, because the defect was never in the arithmetic — TaxRule has
 * been right the whole time — it was in a sentence a controller wrote by hand
 * beside it. A unit test on shelfNote() would have passed on the old code too,
 * since the old code never called it.
 *
 * THE SHIPPED CONFIGURATION IS ASSERTED FIRST AND UNCHANGED: `vat_basis`
 * inclusive, `tax_mode` display, 5%. A shop that applies this package and
 * changes nothing sees the same words it saw yesterday, minus the claim it can
 * now withdraw.
 */

use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\TaxRule;
use App\Support\TrustClaims;
use App\Support\VatDisplay;
use Illuminate\Support\Str;

beforeEach(function () {
    // Three caches, all of which outlive a single test: the service's forever
    // cache, its process memo and Setting::map()'s static. CLAUDE.md records
    // the last one by name.
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
});

/** Every settings write goes through the service, then drops all three caches. */
function svsSet(array $values): void
{
    $settings = app(SettingsService::class);

    foreach ($values as $key => $value) {
        $settings->set($key, $value);
    }

    $settings->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
}

function svsProduct(): Product
{
    return Product::create([
        'slug' => 'svs-' . Str::random(10),
        'name' => 'Rice Probiotics Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ]);
}

/**
 * The VAT line as a shopper reads it, or null when the page draws none.
 *
 * Matched on the ELEMENT rather than on the words: a class-name search of
 * rendered HTML also matches the page's own inlined CSS, which is how an
 * earlier lane convinced itself an element existed when only its stylesheet
 * did. preg_match_all so a second copy of the line cannot hide behind the
 * first.
 */
function svsVatLine(string $html): ?string
{
    $m = [];
    $found = preg_match_all('/<div class="[^"]*\bbb-vat\b[^"]*">(.*?)<\/div>/s', $html, $m);

    expect($found)->toBeLessThan(2, 'the product page drew the VAT line more than once');

    if ($found !== 1) {
        return null;
    }

    return trim(html_entity_decode(strip_tags($m[1][0]), ENT_QUOTES, 'UTF-8'));
}

/** The product page as it renders for a visitor the request says is in $code. */
function svsPage(Product $product, ?string $code = null): string
{
    $request = $code === null ? test() : test()->withHeader('CF-IPCountry', $code);

    return $request->get('/product/' . $product->slug)->assertOk()->getContent();
}

/*
|------------------------------------------------------------------------------
| 1. The basis is read, not asserted
|------------------------------------------------------------------------------
*/

it('says the price contains the tax only where the shop says it contains the tax', function () {
    // The shipped configuration, spelled out rather than assumed.
    svsSet([
        'vat_enabled' => '1',
        'vat_rate' => '5',
        'vat_basis' => TaxRule::INCLUSIVE,
        'tax_mode' => VatDisplay::MODE_DISPLAY,
    ]);

    expect(svsVatLine(svsPage(svsProduct())))->toBe('Inclusive of 5% VAT');
});

it('stops saying "inclusive" the moment the owner sets the basis to exclusive', function () {
    /*
     * THE LINE THIS WHOLE FILE EXISTS FOR. Live mode plus an exclusive basis is
     * the one configuration in which the shelf price is NOT what the customer
     * pays, and it is the configuration the owner asked for. The old sentence
     * said the opposite of the truth here, on every product in the catalogue,
     * from the moment he pressed Save.
     */
    svsSet([
        'vat_enabled' => '1',
        'vat_rate' => '5',
        'vat_basis' => TaxRule::EXCLUSIVE,
        'tax_mode' => VatDisplay::MODE_LIVE,
    ]);

    $line = svsVatLine(svsPage(svsProduct()));

    expect($line)->toBe('+5% VAT added at checkout');
    expect(str_contains(mb_strtolower((string) $line), 'inclusive'))->toBeFalse();
});

it('claims neither "included" nor "added" where the basis charges nobody anything', function () {
    /*
     * `flat` is the legacy basis: a figure printed beside a total it is not part
     * of. And `exclusive` while `tax_mode` is still `display` behaves exactly
     * the same way — the owner has declared the intent but not thrown the
     * switch, so nothing is added yet.
     *
     * Both must avoid BOTH claims. "Inclusive of" would be false (nothing is
     * contained) and "added at checkout" would be false too (nothing is added),
     * and promising a surcharge that never arrives is its own untruth.
     */
    foreach ([TaxRule::FLAT, TaxRule::EXCLUSIVE] as $basis) {
        svsSet([
            'vat_enabled' => '1',
            'vat_rate' => '5',
            'vat_basis' => $basis,
            'tax_mode' => VatDisplay::MODE_DISPLAY,
        ]);

        $line = (string) svsVatLine(svsPage(svsProduct()));

        expect($line)->toBe('5% VAT shown at checkout', 'basis ' . $basis);
        expect(str_contains(mb_strtolower($line), 'inclusive'))->toBeFalse('basis ' . $basis);
        expect(str_contains(mb_strtolower($line), 'added'))->toBeFalse('basis ' . $basis);
    }
});

/*
|------------------------------------------------------------------------------
| 2. It is THIS shopper's country, and changing it changes the sentence
|------------------------------------------------------------------------------
*/

it('gives the UAE and Saudi Arabia different sentences on the same product page', function () {
    /*
     * The owner's own example, configured exactly as he described it. One
     * product, one URL, two visitors — and before this change both of them were
     * told "Inclusive of 5% VAT", which was right for one of them by accident
     * and wrong for the other by construction.
     */
    svsSet([
        'vat_enabled' => '1',
        'vat_rate' => '5',
        'vat_basis' => TaxRule::INCLUSIVE,
        'tax_mode' => VatDisplay::MODE_LIVE,
        'vat_country_rates' => ['AE' => '5', 'SA' => '15'],
        'vat_country_bases' => ['AE' => TaxRule::INCLUSIVE, 'SA' => TaxRule::EXCLUSIVE],
    ]);

    $product = svsProduct();

    expect(svsVatLine(svsPage($product, 'AE')))->toBe('Inclusive of 5% VAT');
    expect(svsVatLine(svsPage($product, 'SA')))->toBe('+15% VAT added at checkout');
});

it('falls back to the default rule for a country the table does not list', function () {
    // Kuwait has no row of its own, so it gets `vat_rate` / `vat_basis` — the
    // owner's "all countries at once" — rather than Saudi Arabia's.
    svsSet([
        'vat_enabled' => '1',
        'vat_rate' => '7.5',
        'vat_basis' => TaxRule::INCLUSIVE,
        'tax_mode' => VatDisplay::MODE_LIVE,
        'vat_country_rates' => ['SA' => '15'],
        'vat_country_bases' => ['SA' => TaxRule::EXCLUSIVE],
    ]);

    expect(svsVatLine(svsPage(svsProduct(), 'KW')))->toBe('Inclusive of 7.5% VAT');
});

it('agrees with the rule the checkout would actually charge', function () {
    /*
     * The sentence and the money come from one authority or they do not agree.
     * Asserted against VatDisplay::quote() — the method every money path asks —
     * rather than against a second copy of the arithmetic written here.
     */
    svsSet([
        'vat_enabled' => '1',
        'vat_rate' => '5',
        'vat_basis' => TaxRule::INCLUSIVE,
        'tax_mode' => VatDisplay::MODE_LIVE,
        'vat_country_rates' => ['SA' => '15'],
        'vat_country_bases' => ['SA' => TaxRule::EXCLUSIVE],
    ]);

    $quote = app(VatDisplay::class)->quote(10000, 'SA');

    expect($quote['added'])->toBeTrue();
    expect($quote['total'])->toBe(11500);

    // The page says the total goes up, and it does.
    expect(svsVatLine(svsPage(svsProduct(), 'SA')))->toBe('+15% VAT added at checkout');
});

/*
|------------------------------------------------------------------------------
| 3. Silence where there is no tax
|------------------------------------------------------------------------------
*/

it('draws no line at all where the shop charges and prints no tax', function () {
    // Switched off, and a zero rate. Both mean "there is nothing true to say",
    // and an empty div with a border is not an improvement on saying nothing.
    foreach ([['vat_enabled' => '0', 'vat_rate' => '5'], ['vat_enabled' => '1', 'vat_rate' => '0']] as $config) {
        svsSet($config + ['vat_basis' => TaxRule::INCLUSIVE, 'tax_mode' => VatDisplay::MODE_DISPLAY]);

        expect(svsVatLine(svsPage(svsProduct())))->toBeNull((string) json_encode($config));
    }
});

it('says nothing for a country whose own rate is zero even while other countries pay', function () {
    svsSet([
        'vat_enabled' => '1',
        'vat_rate' => '5',
        'vat_basis' => TaxRule::INCLUSIVE,
        'tax_mode' => VatDisplay::MODE_LIVE,
        'vat_country_rates' => ['KW' => '0'],
    ]);

    $product = svsProduct();

    expect(svsVatLine(svsPage($product, 'KW')))->toBeNull();
    expect(svsVatLine(svsPage($product, 'AE')))->toBe('Inclusive of 5% VAT');
});

/*
|------------------------------------------------------------------------------
| 4. The claim that was welded to the tax line
|------------------------------------------------------------------------------
*/

it('makes no trust claim inside the tax sentence', function () {
    svsSet([
        'vat_enabled' => '1',
        'vat_rate' => '5',
        'vat_basis' => TaxRule::INCLUSIVE,
        'tax_mode' => VatDisplay::MODE_DISPLAY,
    ]);

    // Built at run time rather than typed, so this file does not itself become
    // a seventh place the sentence is spelled out.
    $banned = 'Authentic,' . ' sourced direct';

    $html = svsPage(svsProduct());

    expect(str_contains($html, $banned))->toBeFalse('the withdrawn claim is still on the product page');
    expect(str_contains((string) svsVatLine($html), 'Authentic'))->toBeFalse();
});

it('lets the owner actually withdraw the authenticity claim from the product page', function () {
    /*
     * THE HALF THAT DID NOT WORK. Clearing the box removed the chip and left the
     * same claim printing under the price. Asserted over the WHOLE page, not
     * over the trust row, because that is where the second copy was hiding.
     *
     * Run through e() first: Blade escapes apostrophes and this needle may grow
     * one the day the owner edits it.
     */
    svsSet([
        'vat_enabled' => '1',
        'vat_rate' => '5',
        'vat_basis' => TaxRule::INCLUSIVE,
        'tax_mode' => VatDisplay::MODE_DISPLAY,
    ]);

    $product = svsProduct();
    $shipped = e(TrustClaims::CLAIMS['product_authentic_text']);

    expect(str_contains(svsPage($product), $shipped))
        ->toBeTrue('the shipped claim should be on the page before it is cleared');

    svsSet(['product_authentic_text' => '']);

    expect(TrustClaims::get('product_authentic_text'))->toBeNull();

    $after = svsPage($product);

    expect(str_contains($after, $shipped))
        ->toBeFalse('clearing the claim has to remove every copy of it, not just the chip');

    // And the tax line is still there, because the claim was the only thing
    // withdrawn.
    expect(svsVatLine($after))->toBe('Inclusive of 5% VAT');
});

it('keeps the basis out of the module registry\'s description of the line', function () {
    /*
     * Store → Modules describes the VAT line to the owner. It used to describe
     * it by QUOTING the old sentence — "Inclusive of 5% VAT · Authentic, sourced
     * direct." — so the admin screen asserted a basis and repeated a trust claim
     * in the same breath, and would have gone on doing both after the storefront
     * stopped.
     *
     * The source scan is token_get_all() rather than a regex: a regex over PHP
     * source reads comments as code, and the comment above that entry
     * deliberately quotes the wording it replaced.
     */
    $strings = [];

    foreach (token_get_all((string) file_get_contents(app_path('Services/ProductSections.php'))) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $strings[] = $token[1];
        }
    }

    $description = \App\Services\ProductSections::REGISTRY['vat'][1];

    expect($strings)->not->toBeEmpty();
    expect(mb_strtolower($description))->not->toContain('inclusive of');
    expect($description)->not->toContain('Authentic');

    foreach ($strings as $literal) {
        expect(mb_strtolower($literal))->not->toContain('sourced direct');
    }
});
