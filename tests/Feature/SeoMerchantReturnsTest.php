<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminController;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Seo;

/**
 * =============================================================================
 * "AT THE MOMENT WE DON'T OFFER RETURNS" — A STATEMENT THIS SHOP COULD NOT MAKE
 * =============================================================================
 *
 * The owner answered the returns question outright. The merchant block gated the
 * whole `MerchantReturnPolicy` on `merchant_return_days > 0` and could emit
 * exactly one category, `MerchantReturnFiniteReturnWindow`, so a shop that takes
 * no returns published its shipping terms and said nothing whatever about
 * returns. Measured on a rendered product page before the change, at the days
 * box set to 0 AND at blank (which AdminController documents as the same value):
 *
 *     shippingDetails            published
 *     hasMerchantReturnPolicy    ABSENT
 *
 * "We do not accept returns" and "we have not told you" left identical markup.
 * schema.org has `MerchantReturnNotPermitted` for the first of those, and a
 * stated policy is worth more in a merchant listing than an absent one.
 *
 * THE OTHER HALF OF THE CARE, and it is the reason this is a select and not a
 * default: publishing "no returns" for a shop that has said nothing would be the
 * identical error to the hardcoded `FreeReturn` this block already had to take
 * back out. Section 1 is the rule-1 pin that says it does not happen.
 */

const SMR_BASE = 'https://kbeautybliss.test';

function smrSettings(array $values = []): void
{
    foreach (array_merge([
        'site_url' => SMR_BASE,
        'seo_site_name' => 'K-Beauty Bliss',
    ], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
}

function smrProduct(): Product
{
    return Product::create([
        'slug' => 'smr-toner', 'name' => 'SMR Toner', 'sku' => 'SMR-1', 'status' => 'publish',
        'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
        'image' => 'https://cdn.test/smr.jpg',
        'short_description' => 'A toner with comfortably more than eight words in its description.',
    ]);
}

/** The Offer node off a rendered product page, or [] if there is none. */
function smrOffer(): array
{
    $html = (string) test()->get('/product/smr-toner/')->getContent();

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    foreach ($m[1] as $json) {
        $node = json_decode(html_entity_decode($json, ENT_QUOTES), true);

        if (is_array($node) && ($node['@type'] ?? '') === 'Product' && isset($node['offers'])) {
            return $node['offers'];
        }
    }

    return [];
}

/* ==========================================================================
 * 1. RULE 1: NOTHING MOVES ON A SHOP THAT HAS NOT ANSWERED
 * ========================================================================== */

it('publishes no returns policy at all while the owner has stated nothing', function () {
    /*
     * The shipped state, and the state of every shop the day this package is
     * applied: no `merchant_returns` row exists, so the days box alone decides
     * exactly as it did before the select existed.
     *
     * Both spellings of "no window" are checked because
     * AdminController::SETTING_RULES documents them as one value: a typed 0 and
     * a cleared box are the same answer to the only reader this key has.
     *
     * MUTATION NOTE: make Seo::returnPolicy() fall through to
     * MerchantReturnNotPermitted when nothing is stated — the tempting shortcut,
     * since the owner has told US he takes no returns — and this is red. It has
     * to be: his shop is not the only shop this code runs on, and the setting is
     * where his answer is recorded.
     */
    smrProduct();

    foreach (['0', ''] as $days) {
        /*
         * `merchant_ship_cost` IS STATED HERE, and it was not when this test was
         * written -- Lane S8. The claim being made is "the returns change left
         * shipping alone", so shipping has to be in a state where it publishes
         * at all. It no longer publishes from a blank cost: an unstated delivery
         * rate used to be read as `?? 0` and emitted as a free-delivery promise.
         * See Seo::shippingDetails(). Stating 20 here keeps this test asking its
         * own question instead of silently re-pinning that defect.
         */
        smrSettings([
            'enable_merchant' => '1', 'merchant_ship_country' => 'AE',
            'merchant_ship_cost' => '20', 'merchant_return_days' => $days,
        ]);

        $offer = smrOffer();

        // array_key_exists(), not ->toHaveKey($key, $message): toHaveKey()'s
        // second argument is an expected VALUE, and that mistake is on record
        // twice in docs/SEO-ARABIC-PARITY.md as an assertion that cannot fail.
        expect(array_key_exists('shippingDetails', $offer))
            ->toBeTrue('shipping is unaffected either way')
            ->and(array_key_exists('hasMerchantReturnPolicy', $offer))
            ->toBeFalse('days='.var_export($days, true).' with nothing stated must publish no policy');
    }
});

it('still publishes a finite window from the days box alone, unchanged', function () {
    /*
     * The behaviour every shop that HAS filled the box in already has. If this
     * moves, a shop advertising a 14-day window in a Google result stops
     * advertising it, which is a rule-1 regression with a commercial cost.
     */
    smrProduct();
    smrSettings([
        'enable_merchant' => '1', 'merchant_ship_country' => 'AE', 'merchant_return_days' => '14',
    ]);

    expect(smrOffer()['hasMerchantReturnPolicy'] ?? null)->toBe([
        '@type' => 'MerchantReturnPolicy',
        'applicableCountry' => 'AE',
        'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
        'merchantReturnDays' => 14,
    ]);
});

/* ==========================================================================
 * 2. THE ANSWER HE GAVE
 * ========================================================================== */

it('publishes MerchantReturnNotPermitted once the owner says this shop takes no returns', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE TEST THAT IS RED ON THE OLD CODE
     * ════════════════════════════════════════════════════════════════════════
     *
     * Before this round there was no value of any setting that produced this
     * node. `merchant_returns` did not exist and the only category the block
     * could emit was the finite window.
     *
     * MUTATION NOTE: remove 'MerchantReturnNotPermitted' from
     * Seo::RETURN_CATEGORIES and the value falls back to "not stated", so the
     * policy disappears and this is red. Verified.
     */
    smrProduct();
    smrSettings([
        'enable_merchant' => '1', 'merchant_ship_country' => 'AE',
        'merchant_return_days' => '0', 'merchant_returns' => 'MerchantReturnNotPermitted',
    ]);

    expect(smrOffer()['hasMerchantReturnPolicy'] ?? null)->toBe([
        '@type' => 'MerchantReturnPolicy',
        'applicableCountry' => 'AE',
        'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
    ]);
});

it('never puts a return window, method or fee beside a policy that permits no returns', function () {
    /*
     * A `merchantReturnDays` beside `MerchantReturnNotPermitted` is markup that
     * contradicts itself — a window on a policy with no window — and the owner
     * can reach that state easily, by choosing "No returns" without clearing a
     * number he typed earlier. `returnMethod` and `returnFees` are worse: the
     * way a refused return travels, and the fee for it, are promises nobody can
     * make. All three are suppressed.
     *
     * MUTATION NOTE: let the NotPermitted branch fall through to the shared
     * tail that appends merchantReturnDays/returnMethod/returnFees and this is
     * red on all three keys. Verified.
     */
    smrProduct();
    smrSettings([
        'enable_merchant' => '1', 'merchant_ship_country' => 'AE',
        'merchant_return_days' => '14',
        'merchant_return_method' => 'ReturnByMail',
        'merchant_return_fees' => 'FreeReturn',
        'merchant_returns' => 'MerchantReturnNotPermitted',
    ]);

    $policy = smrOffer()['hasMerchantReturnPolicy'] ?? [];

    expect($policy['returnPolicyCategory'] ?? null)->toBe('https://schema.org/MerchantReturnNotPermitted')
        ->and(array_key_exists('merchantReturnDays', $policy))->toBeFalse()
        ->and(array_key_exists('returnMethod', $policy))->toBeFalse()
        ->and(array_key_exists('returnFees', $policy))->toBeFalse();
});

it('refuses to invent a window when a finite policy is chosen with no number in the box', function () {
    /*
     * The other half of separating the two questions: "how long" cannot be
     * guessed from "yes we take returns". A finite window with no window
     * publishes nothing, which is the same answer this block gave before.
     */
    smrProduct();
    smrSettings([
        'enable_merchant' => '1', 'merchant_ship_country' => 'AE',
        'merchant_return_days' => '0', 'merchant_returns' => 'MerchantReturnFiniteReturnWindow',
    ]);

    expect(array_key_exists('hasMerchantReturnPolicy', smrOffer()))->toBeFalse();
});

it('says nothing about returns while the merchant block itself is off', function () {
    /*
     * `enable_merchant` is the gate the whole block sits behind and it ships
     * off, so the new select cannot publish anything on a shop that has not
     * turned merchant listings on at all. Checked with the strongest value the
     * select carries, because that is the one that would be visible.
     */
    smrProduct();
    smrSettings([
        'enable_merchant' => '0', 'merchant_ship_country' => 'AE',
        'merchant_returns' => 'MerchantReturnNotPermitted',
    ]);

    $offer = smrOffer();

    expect(array_key_exists('hasMerchantReturnPolicy', $offer))->toBeFalse()
        ->and(array_key_exists('shippingDetails', $offer))->toBeFalse();
});

/* ==========================================================================
 * 3. RULE 5: THE SELECT STORES ONE OF ITS OWN OPTIONS
 * ========================================================================== */

it('publishes one of two constant URLs and never the settings own text', function () {
    /*
     * Rule 5, and the mechanism is worth stating because it is NOT the
     * in_array() check — that is defence in depth. `returnPolicyCategory` is
     * assigned from a STRING LITERAL in each branch, so no value any row holds
     * can reach the document: the setting only chooses which of two constants
     * is printed. "Anything printed unescaped is a constant, never a setting."
     *
     * MUTATION NOTE, AND THE ONE THAT CAME BACK GREEN. Deleting the in_array()
     * allowlist is NOT caught by this file, and I ran it to find that out:
     * `$category = $stated` leaves a hostile value failing the `===` against
     * the NotPermitted literal, so it falls through to the days branch and the
     * document is unchanged. The allowlist is therefore belt to the literals'
     * braces rather than the thing holding the value out, and this test now
     * pins the half that actually does. Interpolating $category into the URL on
     * its own is not caught either, for the same reason in reverse — the
     * allowlist has already emptied it. The two guards are REDUNDANT, which is
     * the point of having both, and what this test catches is either one being
     * removed at the same time as the other: allowlist dropped AND the URL
     * built from the value is red, with the payload below in the page. All three
     * mutations run; two green, one red, reported that way.
     */
    smrProduct();

    $hostile = 'MerchantReturnNotPermitted"},"hasMerchantReturnPolicy":{"@type":"Evil';

    smrSettings([
        'enable_merchant' => '1', 'merchant_ship_country' => 'AE', 'merchant_return_days' => '14',
        'merchant_returns' => $hostile,
    ]);

    $offer = smrOffer();

    // Off the list, so it is "not stated" and the days box answers — with the
    // constant, not with a string built from the row.
    expect($offer['hasMerchantReturnPolicy']['returnPolicyCategory'] ?? null)
        ->toBe('https://schema.org/MerchantReturnFiniteReturnWindow');

    $html = (string) test()->get('/product/smr-toner/')->getContent();

    expect($html)->not->toContain('Evil')
        ->and($html)->not->toContain($hostile);
});

it('validates the setting against exactly the categories the emitter knows', function () {
    /*
     * A key absent from AdminController::SETTING_RULES is a key the SEO screen
     * can post and the server silently drops — the "Saved on screen, no row in
     * the table" failure SeoVerificationTagsTest was written after. And a list
     * that drifts from Seo::RETURN_CATEGORIES is a select offering a value the
     * emitter refuses, which is the same failure wearing a different hat.
     *
     * The blank is an option in its own right: without it, choosing "Not
     * stated" again after choosing something else would fail validation and
     * take the whole SEO tab down with it.
     */
    $rules = AdminController::SETTING_RULES['merchant_returns'] ?? null;

    expect($rules)->not->toBeNull('merchant_returns must be savable at all')
        ->and($rules[0])->toBe('enum')
        ->and($rules[2])->toBe(array_merge([''], Seo::RETURN_CATEGORIES));
});
