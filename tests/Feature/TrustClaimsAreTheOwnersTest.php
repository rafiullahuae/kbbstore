<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminController;
use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Support\TrustClaims;
use Illuminate\Support\Str;

/**
 * Lane DR — the shop's trust claims belong to the shop's owner.
 *
 * ── WHAT WAS THERE ──────────────────────────────────────────────────────────
 *
 * Six statements about how this business buys stock and how many hours a day it
 * answers, made to every visitor, none of them verified by anybody and none of
 * them reachable by the person responsible for them:
 *
 *   store/home.blade.php                     "100% original"
 *                                            "Direct from brands and trusted
 *                                             suppliers"
 *                                            "24/7 support"
 *                                            "Korean brands, all sourced direct."
 *   partials/checkout/order-block.blade.php  "100% authentic", the last line
 *                                            before Place order
 *   partials/announcement.blade.php          "100% authentic K-beauty"
 *
 * plus one that LOOKED settings-driven and was not:
 * `reassure_auth_text` on the checkout reassurance block had no entry in
 * AdminController::SETTING_RULES, and updateSettings() rejects every key that
 * is not in that list — so the screen would have answered "Saved" and written
 * nothing. The default was the shipped and only value.
 *
 * ── WHAT IS PINNED ──────────────────────────────────────────────────────────
 *
 *   1. Nothing moved on the day this shipped: with no settings rows, every page
 *      still reads exactly what it read before.
 *   2. The owner can change each one, and the page says his words.
 *   3. CLEARING one removes the badge — icon and all — rather than leaving an
 *      empty box. This is the half that matters: "I cannot stand behind this"
 *      has to be sayable, and in a text field the only way to say it is to
 *      empty the field. SettingsService::get() returns its default only when
 *      the ROW IS ABSENT, so a cleared box stores '' and must not fall back.
 *   4. Every key is writable through the admin endpoint, i.e. present in
 *      SETTING_RULES. A claim in a settings box nobody can post to is the same
 *      defect wearing a different hat.
 *   5. Counts are NOT claims: the home page's brand, product and review figures
 *      stay derived from the database and are deliberately not routed through
 *      TrustClaims, so no text box can make them drift from the catalogue.
 *
 * ── THE TRAP THIS FILE IS WRITTEN AROUND ────────────────────────────────────
 *
 * A WORD SEARCH OF RENDERED HTML MATCHES THE INLINED CSS AND THE BLADE
 * COMMENTS' compiled output. The storefront inlines its stylesheets. Every
 * assertion below reads text a shopper would read, matched against the visible
 * document with tags stripped, and the "removed" assertions check the ELEMENT
 * is gone rather than that a word is absent.
 *
 * Settings are written through SettingsService and never with a raw insert:
 * the service holds a forever-cache AND a per-process memo, so a row written
 * behind its back is invisible for the rest of the process.
 */

/* ---------------------------------------------------------------- fixtures */

function tcSet(string $key, string $value): void
{
    app(SettingsService::class)->set($key, $value);
}

/** The home page as a shopper reads it: tags gone, whitespace collapsed. */
function tcHomeText(): string
{
    $html = test()->get('/')->assertOk()->getContent();

    return trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
}

/** The trust cards on the home page, as elements rather than as words. */
function tcTrustCardTitles(): array
{
    $html = test()->get('/')->assertOk()->getContent();

    if (! preg_match('#<div class="trust">.*?</div>\s*</div>\s*</div>#s', $html, $m)) {
        return [];
    }

    preg_match_all('#<b>(.*?)</b>#s', $m[0], $titles);

    return array_map(static fn ($t) => trim(strip_tags($t)), $titles[1]);
}

function tcCheckoutHtml(): string
{
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    $product = Product::create([
        'slug' => 'tc-' . Str::random(8),
        'name' => 'Rice Probiotics Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 13000]);

    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/checkout/')->assertOk()->getContent();
}

/*
|------------------------------------------------------------------------------
| 1. Nothing changed for a shop that changes nothing
|------------------------------------------------------------------------------
*/

it('still reads exactly as it shipped when no setting has been written', function () {
    $text = tcHomeText();

    foreach ([
        TrustClaims::CLAIMS['trust_authentic_title'],
        TrustClaims::CLAIMS['trust_authentic_text'],
        TrustClaims::CLAIMS['trust_support_title'],
        TrustClaims::CLAIMS['home_brands_note'],
    ] as $shipped) {
        expect(str_contains($text, $shipped))
            ->toBeTrue('The home page lost "' . $shipped . '" on a shop with no settings.');
    }
});

it('still prints the shipped checkout claim when no setting has been written', function () {
    $html = tcCheckoutHtml();
    $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));

    expect(str_contains($text, TrustClaims::CLAIMS['checkout_authentic_text']))
        ->toBeTrue('The checkout lost its shipped authenticity chip.');
});

/*
|------------------------------------------------------------------------------
| 2. The owner's words win
|------------------------------------------------------------------------------
*/

it('prints the owner wording on the home page instead of the shipped default', function () {
    tcSet('trust_authentic_title', 'Sourced from authorised distributors');
    tcSet('trust_support_title', 'Support, 9am to 6pm');
    tcSet('home_brands_note', 'The brands we carry today.');

    $text = tcHomeText();

    expect(str_contains($text, 'Sourced from authorised distributors'))->toBeTrue('The owner title did not reach the page.');
    expect(str_contains($text, 'Support, 9am to 6pm'))->toBeTrue('The owner support title did not reach the page.');
    expect(str_contains($text, 'The brands we carry today.'))->toBeTrue('The owner brands note did not reach the page.');

    expect(str_contains($text, TrustClaims::CLAIMS['trust_authentic_title']))
        ->toBeFalse('The shipped claim is still on the page alongside the owner one.');
});

it('prints the owner wording on the checkout instead of the shipped default', function () {
    tcSet('checkout_authentic_text', 'Sealed and shipped by us');

    $html = tcCheckoutHtml();

    /*
     * SCOPED TO THE CHIP ROW, NOT TO THE WHOLE DOCUMENT.
     *
     * A first draft searched the entire checkout for the shipped wording and
     * failed: "100% authentic" is a SUBSTRING of "100% authentic K-beauty",
     * which is the reassurance block's own claim a few hundred pixels higher up
     * the same page. Two different claims, two different settings, one of them
     * containing the other — so the assertion has to name which element it is
     * talking about.
     */
    expect((bool) preg_match('#<div class="trust">(.*?)</div>#s', $html, $m))
        ->toBeTrue('The checkout rendered no trust row.');

    $row = strip_tags($m[1]);

    expect(str_contains($row, 'Sealed and shipped by us'))->toBeTrue('The owner chip did not reach the checkout.');
    expect(str_contains($row, TrustClaims::CLAIMS['checkout_authentic_text']))
        ->toBeFalse('The shipped chip survived alongside the owner one.');
});

/*
|------------------------------------------------------------------------------
| 3. Clearing a claim removes it, and removes the whole badge
|------------------------------------------------------------------------------
*/

it('drops the home trust card entirely when the claim is cleared', function () {
    $before = tcTrustCardTitles();

    expect($before)->toContain(TrustClaims::CLAIMS['trust_authentic_title']);

    tcSet('trust_authentic_title', '');

    $after = tcTrustCardTitles();

    expect(count($after))->toBe(
        count($before) - 1,
        'Clearing the claim left an empty card behind instead of removing it.',
    );

    expect(in_array(TrustClaims::CLAIMS['trust_authentic_title'], $after, true))
        ->toBeFalse('A cleared claim fell back to the shipped wording.');
});

it('drops the support card entirely when its title is cleared', function () {
    $before = count(tcTrustCardTitles());

    tcSet('trust_support_title', '');

    expect(count(tcTrustCardTitles()))->toBe($before - 1, 'The cleared support card is still rendered.');
});

it('drops the brands note rather than rendering an empty paragraph', function () {
    tcSet('home_brands_note', '');

    $html = $this->get('/')->assertOk()->getContent();

    expect(str_contains($html, TrustClaims::CLAIMS['home_brands_note']))
        ->toBeFalse('A cleared brands note fell back to the shipped wording.');

    expect((bool) preg_match('#<p>\s*</p>#', $html))
        ->toBeFalse('Clearing the brands note left an empty paragraph on the page.');
});

it('drops the checkout chip entirely when it is cleared', function () {
    tcSet('checkout_authentic_text', '');

    $html = tcCheckoutHtml();

    expect((bool) preg_match('#<div class="trust">(.*?)</div>#s', $html, $m))->toBeTrue();

    // One chip left — SSL secure — and it is an element count, not a word
    // search, because the checkout inlines a stylesheet that mentions .trust.
    expect(preg_match_all('#<span>#', $m[1]))->toBe(1, 'The cleared chip is still rendered.');

    expect(str_contains(strip_tags($m[1]), TrustClaims::CLAIMS['checkout_authentic_text']))
        ->toBeFalse('A cleared checkout chip fell back to the shipped wording.');
});

it('returns null for a cleared claim and the default for an absent one', function () {
    $settings = app(SettingsService::class);

    expect(TrustClaims::text($settings, 'reassure_auth_text'))
        ->toBe(TrustClaims::CLAIMS['reassure_auth_text']);

    tcSet('reassure_auth_text', '   ');

    expect(TrustClaims::text($settings, 'reassure_auth_text'))
        ->toBeNull('Whitespace in the box is an empty claim, not the shipped one.');
});

/*
|------------------------------------------------------------------------------
| 4. Every claim is writable through the admin endpoint
|------------------------------------------------------------------------------
*/

it('accepts every trust-claim key on the settings endpoint', function () {
    foreach (array_keys(TrustClaims::CLAIMS) as $key) {
        expect(array_key_exists($key, AdminController::SETTING_RULES))
            ->toBeTrue($key . ' is read by a template and rejected by updateSettings(); the box would not save.');

        expect(AdminController::SETTING_RULES[$key][0])
            ->toBe('text', $key . ' must be free text — a rule that refuses blanks takes away the owner\'s "no".');
    }
});

/*
|------------------------------------------------------------------------------
| 5. What is countable stays counted
|------------------------------------------------------------------------------
*/

it('keeps the home page counts out of the settings boxes', function () {
    // The brand, product and review figures are read off the catalogue. Routing
    // them through a text box would let a typed number drift away from what the
    // shop actually carries, which is the defect this lane exists to remove,
    // not a fix for it.
    foreach (array_keys(TrustClaims::CLAIMS) as $key) {
        expect(preg_match('/(count|total|brands_n|products_n|reviews_n)$/', $key))
            ->toBe(0, $key . ' looks like a countable figure in a text box.');
    }

    // And the figure on the page is the figure in the database.
    for ($i = 0; $i < 3; $i++) {
        Product::create([
            'slug' => 'tc-count-' . $i,
            'name' => 'Counted ' . $i,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 1000 + $i,
            'stock_status' => 'instock',
        ]);
    }

    \Illuminate\Support\Facades\Cache::flush();

    /*
     * MATCHED AS AN ELEMENT PAIR, NOT AS A PHRASE.
     *
     * The stat is `<b>3</b><span>products stocked</span>`, so stripping tags
     * runs the figure into the label — "3products stocked" — and a search for
     * the phrase fails whether or not the number is right. The count and its
     * label are two elements and are read as two elements.
     */
    $html = test()->get('/')->assertOk()->getContent();

    expect((bool) preg_match('#<b>([\d,]+)</b><span>products stocked</span>#', $html, $m))
        ->toBeTrue('The home page no longer prints a counted product stat at all.');

    /*
     * COMPARED AGAINST THE QUERY, NOT AGAINST A NUMBER TYPED HERE.
     *
     * A first draft expected "3" — the three products this test creates — and
     * read 27, because the migration set seeds a demo catalogue of its own. The
     * figure this test is about is "whatever the catalogue holds", so it asks
     * the catalogue rather than asserting an arithmetic the fixtures do not
     * control.
     */
    expect($m[1])->toBe(
        number_format(Product::query()->visible()->count()),
        'The home page stat is no longer counted from the catalogue.',
    );
});
