<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminController;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Seo\SeoSettings;
use App\Services\SettingsService;
use App\Support\BusinessAddress;
use App\Support\SeoAudit;

/**
 * =============================================================================
 * "WE OPERATE ONLY ONLINE", AND THE FREE DELIVERY NOBODY PROMISED — Lane S8
 * =============================================================================
 *
 * Two of the owner's open questions, answered in his own words, and the two
 * defects that answering them uncovered.
 *
 * ── HIS WORDS ───────────────────────────────────────────────────────────────
 *
 *   "we are open 24/7, we don't have any physical shop, we operate only online"
 *   "at the moment we don't offer returns"
 *
 * ── DEFECT 1: THE SHOP PUBLISHED THE LEAST SPECIFIC TRUE ANSWER ────────────
 *
 * `org_type` shipped at `Organization`, so every page said "this is a company"
 * about a shop that sells things. `OnlineStore` is the schema.org type for a
 * retailer with no premises and it is what ships now. This is a DEFAULT CHANGE
 * and CLAUDE.md rule 1's one exception -- a default the owner asked for in as
 * many words -- so it is called out here and in the commit, and section 1 below
 * measures exactly which bytes moved (one property of one node) and which did
 * not.
 *
 * ── DEFECT 2: FREE DELIVERY TO THE WHOLE UAE, PROMISED BY A PREFILLED ZERO ──
 *
 * This is the one that would have cost money. The owner's returns answer is
 * publishable -- Lane S5 built `MerchantReturnNotPermitted` for it -- and the
 * ONLY way to publish it is to switch `enable_merchant` on. Doing that used to
 * publish `shippingDetails` unconditionally, with the rate read as
 * `Money::fromMajor($s['merchant_ship_cost'] ?? 0)`. That key ships blank,
 * `SeoSettings::map()` drops blanks, so `?? 0` fired and every product page went
 * out with:
 *
 *     "shippingRate":{"@type":"MonetaryAmount","value":"0.00","currency":"AED"}
 *
 * Driven off a rendered product page and read back before the fix, not reasoned
 * about. A shipping rate in a merchant listing is a number Google prints beside
 * the price to a shopper; this shop's delivery terms are genuinely unknown and
 * nobody may guess them. The screen made it worse rather than better, because
 * the box arrived holding `0` -- it had to, since the `aed` rule refused a blank
 * and an empty box would have failed the whole save.
 *
 * So the one switch that let the owner state a thing he knows also stated a
 * thing he does not. All three halves are fixed: the rule accepts blank, the box
 * ships blank, and the emitter publishes nothing about shipping until a rate has
 * actually been entered.
 */
function soSettings(array $values = []): void
{
    foreach (array_merge([
        'site_url' => 'https://kbeautybliss.test',
        'seo_site_name' => 'K-Beauty Bliss',
    ], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
}

function soProduct(): Product
{
    return Product::create([
        'slug' => 'so-serum', 'name' => 'SO Serum', 'sku' => 'SO-1', 'status' => 'publish',
        'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
        'image' => 'https://cdn.test/so.jpg',
        'short_description' => 'A serum with comfortably more than eight words in its description.',
    ]);
}

/** Every JSON-LD node on a URL, decoded. */
function soNodes(string $url): array
{
    $html = (string) test()->get($url)->getContent();

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_values(array_filter(array_map(
        static fn (string $json) => json_decode(html_entity_decode($json, ENT_QUOTES), true),
        $m[1]
    ), 'is_array'));
}

/** The Product node's offers, or []. */
function soOffer(): array
{
    foreach (soNodes('/product/so-serum/') as $node) {
        if (($node['@type'] ?? '') === 'Product' && isset($node['offers'])) {
            return $node['offers'];
        }
    }

    return [];
}

/** The organization node on the homepage — whatever @type it carries. */
function soOrgNode(): array
{
    foreach (soNodes('/') as $node) {
        if (isset($node['name'], $node['url']) && ($node['@type'] ?? '') !== 'WebSite') {
            return $node;
        }
    }

    return [];
}

/* ==========================================================================
 * 1. THE BUSINESS TYPE SAYS ONLINE
 * ========================================================================== */

it('publishes this shop as an OnlineStore, on a shop that has saved nothing', function () {
    /*
     * MUTATION NOTE: put SeoSettings::DEFAULTS['org_type'] back to
     * 'Organization' and this is red. That is the whole of the change, so this
     * is the whole of the pin.
     */
    soSettings();

    expect(SeoSettings::get('org_type'))->toBe('OnlineStore');
    expect(soOrgNode()['@type'] ?? null)->toBe('OnlineStore');
});

it('moves nothing else on the node, and publishes no address it was not given', function () {
    /*
     * Rule 1, measured rather than asserted. The default change is ONE property
     * of ONE node; everything else about the organization node is what it was.
     * And "enter the address later" is genuinely supported: no street, no
     * emirate, no phone, no coordinates and no opening hours appear anywhere,
     * because none were given.
     *
     * MUTATION NOTE: add `address` to BusinessAddress::organizationFragment()'s
     * unconditional output and the address expectations go red.
     */
    soSettings();

    $node = soOrgNode();

    expect(array_keys($node))->toBe(['@context', '@type', 'name', 'url']);

    foreach (['address', 'telephone', 'geo', 'openingHoursSpecification'] as $absent) {
        expect(array_key_exists($absent, $node))->toBeFalse();
    }

    expect(BusinessAddress::postal(SeoSettings::map()))->toBeNull();
    expect(BusinessAddress::organizationFragment(SeoSettings::map(), 'OnlineStore'))->toBe([]);
});

it('does not nag the owner for an address he has deliberately not given', function () {
    /*
     * THE POINT OF THE TYPE BEING HONEST. `business_type_mismatch` fires on a
     * PLACE type with no usable address -- a shopfront the shop has not
     * described. `OnlineStore` is not a place type, so the shipped configuration
     * raises nothing, and the owner can fill the address in whenever he likes
     * (or never) without a red card waiting for him.
     *
     * MUTATION NOTE: ship org_type at 'Store' instead and this is red, which is
     * the contradiction Lane S5 built the finding for.
     */
    soSettings();

    $findings = SeoAudit::run()['findings'] ?? [];

    expect($findings['business_type_mismatch']['count'] ?? null)->toBe(0);
});

it('reports opening hours that cannot be published, because 24/7 is not a property of an online shop', function () {
    /*
     * THE DEFECT: `openingHoursSpecification` is a property of schema.org
     * **Place**, and BusinessAddress::isPlaceType() therefore drops it on an
     * OnlineStore -- correctly. It said nothing about doing so. An owner who
     * knows "we are open 24/7" and types it into Store -> Business Details ->
     * Business -> Opening hours got "Saved", a stored value, and not one byte on
     * any page. The audit already caught the identical mistake made with map
     * coordinates and looked straight past the field next to them.
     *
     * There is no honest way to publish 24/7 for a shop with no premises: the
     * property describes a door. So this is REPORTED and not emitted.
     *
     * MUTATION NOTE: drop `|| $hasHours` from SeoAudit::scanBusinessType() and
     * the second block is red. Make organizationFragment() emit the hours on a
     * non-place type and the last expectation is red.
     */
    soSettings();

    // Nothing typed: nothing to report.
    expect(SeoAudit::run()['findings']['business_type_mismatch']['count'] ?? null)->toBe(0);

    // The owner writes down what he told us.
    soSettings(['store_hours' => 'Mon-Sun 00:00-23:59']);

    $finding = SeoAudit::run()['findings']['business_type_mismatch'] ?? [];

    expect($finding['count'] ?? 0)->toBe(1);
    expect(str_contains((string) ($finding['samples'][0]['detail'] ?? ''), 'opening hours are'))->toBeTrue();
    expect(str_contains((string) ($finding['samples'][0]['detail'] ?? ''), '24/7'))->toBeTrue();

    // And it is still not published, which is the correct half.
    expect(array_key_exists(
        'openingHoursSpecification',
        BusinessAddress::organizationFragment(SeoSettings::map(), 'OnlineStore')
    ))->toBeFalse();

    expect(array_key_exists('openingHoursSpecification', soOrgNode()))->toBeFalse();
});

/* ==========================================================================
 * 2. NO DELIVERY RATE IS INVENTED
 * ========================================================================== */

it('publishes nothing about shipping while no rate has been entered', function () {
    /*
     * THE DEFECT, AS IT LOOKED ON THE SHOP: with the merchant switch on and the
     * shipping-cost box never touched, every product page carried
     * "shippingRate":{"value":"0.00","currency":"AED"} -- a free-delivery
     * promise to the whole UAE, printed by Google beside the price.
     *
     * MUTATION NOTE: replace the guard in Seo::shippingDetails() with the old
     * `Money::fromMajor($s['merchant_ship_cost'] ?? 0)` and this is red on the
     * first expectation, naming the 0.00 it publishes.
     */
    soProduct();
    soSettings(['enable_merchant' => '1', 'merchant_ship_country' => 'AE']);

    // The key really is absent from what the emitter reads, which is what makes
    // "not stated" distinguishable from a typed zero at all.
    expect(array_key_exists('merchant_ship_cost', SeoSettings::map()))->toBeFalse();

    $offer = soOffer();

    expect(array_key_exists('shippingDetails', $offer))->toBeFalse();

    // And the string itself is nowhere on the page, not merely absent from the
    // decoded node -- there is one graph and one encoder, so this is a real check.
    expect(str_contains((string) test()->get('/product/so-serum/')->getContent(), 'shippingRate'))->toBeFalse();
});

it('still publishes free delivery when the owner actually typed a zero', function () {
    /*
     * The other side of the discriminator, and the reason it is
     * array_key_exists() and not `> 0`. A shop with genuinely free delivery has
     * a real answer and it publishes. SeoSettings::map() drops blanks and keeps
     * '0'; its own docblock names merchant_ship_cost as the example.
     *
     * MUTATION NOTE: make the guard `(int) ($s['merchant_ship_cost'] ?? 0) > 0`
     * — the obvious wrong fix — and this is red.
     */
    soProduct();
    soSettings(['enable_merchant' => '1', 'merchant_ship_country' => 'AE', 'merchant_ship_cost' => '0']);

    $offer = soOffer();

    expect($offer['shippingDetails']['shippingRate']['value'] ?? null)->toBe('0.00');
});

it('publishes a stated rate exactly as it was typed', function () {
    soProduct();
    soSettings(['enable_merchant' => '1', 'merchant_ship_country' => 'AE', 'merchant_ship_cost' => '20']);

    $offer = soOffer();

    expect($offer['shippingDetails']['shippingRate']['value'] ?? null)->toBe('20.00');
    expect($offer['shippingDetails']['shippingDestination']['addressCountry'] ?? null)->toBe('AE');
});

/* ==========================================================================
 * 3. THE OWNER CAN PUBLISH WHAT HE KNOWS WITHOUT PUBLISHING WHAT HE DOES NOT
 * ========================================================================== */

it('publishes the returns refusal on its own, with no shipping claim beside it', function () {
    /*
     * THIS IS THE WHOLE POINT OF THE LANE'S ITEM. The owner knows one of the two
     * merchant facts. Before this change the switch that let him say it also said
     * the other one, wrongly. Now the two are independent: returns publish from
     * the returns select, shipping publishes from the shipping box, and neither
     * waits on or invents the other.
     *
     * This is the exact configuration to leave the shop in once he is ready:
     * merchant listing ON, returns "We do not accept returns", shipping blank.
     *
     * MUTATION NOTE: put shippingDetails back inside the same unconditional
     * block as the return policy and the second expectation is red.
     */
    soProduct();
    soSettings([
        'enable_merchant' => '1',
        'merchant_ship_country' => 'AE',
        'merchant_returns' => 'MerchantReturnNotPermitted',
    ]);

    $offer = soOffer();

    expect($offer['hasMerchantReturnPolicy']['returnPolicyCategory'] ?? null)
        ->toBe('https://schema.org/MerchantReturnNotPermitted');

    expect(array_key_exists('shippingDetails', $offer))->toBeFalse();

    // A refusal carries no window, method or fee -- Lane S5's rule, re-driven
    // here because this configuration is the one the shop will actually run.
    foreach (['merchantReturnDays', 'returnMethod', 'returnFees'] as $absent) {
        expect(array_key_exists($absent, $offer['hasMerchantReturnPolicy']))->toBeFalse();
    }
});

it('lets the shipping boxes be saved blank, which is what makes "not stated" reachable', function () {
    /*
     * THE THIRD HALF OF THE DEFECT, and without it the other two are unusable:
     * the `aed` rule refused '' , so an empty shipping-cost box failed the save
     * and the screen prefilled `0` to avoid it. A control whose only saveable
     * values are claims is a control that manufactures a claim.
     *
     * MUTATION NOTE: delete the `if ($value === '') return $ok('');` guard from
     * the `aed` branch of AdminController::checkSetting() and this is red.
     */
    $method = new ReflectionMethod(AdminController::class, 'checkSetting');
    $method->setAccessible(true);
    $controller = (new ReflectionClass(AdminController::class))->newInstanceWithoutConstructor();

    foreach (['merchant_ship_cost', 'merchant_ship_free_over'] as $key) {
        $rule = AdminController::SETTING_RULES[$key];

        $blank = $method->invoke($controller, $rule[0], $rule[1], '', $rule[2] ?? null);

        expect($blank['error'])->toBeNull();
        expect($blank['value'])->toBe('');

        // And blank is not a way past the rule for a value that IS given.
        expect($method->invoke($controller, $rule[0], $rule[1], 'abc', $rule[2] ?? null)['error'])
            ->not->toBeNull();
    }
});

it('does not prefill the shipping cost box with a zero the owner never typed', function () {
    /*
     * The screen half, pinned in the one place it can be: the admin shell.
     * `S.merchant_ship_cost||'0'` is what put a free-delivery promise one Save
     * away from going out on every product page.
     *
     * MUTATION NOTE: put `||'0'` back after S.merchant_ship_cost and this is red.
     */
    $blade = (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect(substr_count($blade, "sesc(S.merchant_ship_cost)"))->toBe(1);
    expect(str_contains($blade, "S.merchant_ship_cost||'0'"))->toBeFalse();
});
