<?php

declare(strict_types=1);

/**
 * The two figures the hero quoted, and the counters that own them. (Lane FR)
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * The shipped hero made two measurable claims and measured neither.
 *
 *   "93 brands · sourced direct"   The brands strip forty lines below counts
 *                                  the real number off the catalogue. On the
 *                                  preview database that number is 8. Two
 *                                  answers to one question, on one page, and
 *                                  the unchangeable one was the louder.
 *                                  HomeController's header records the same
 *                                  figure — `max($brandTotal, 93)` — being
 *                                  taken out of the About band and the shop
 *                                  filters for exactly this reason. The hero
 *                                  was where it survived.
 *
 *   "…over AED 199"                The free-delivery threshold is owned by
 *                                  Store → Shipping, per zone, and production
 *                                  runs two zones with two different ones. The
 *                                  delivery band and the promo ticker INSIDE
 *                                  THIS SAME HERO were repaired to read the
 *                                  real figure by an earlier lane; the slide
 *                                  above them went on quoting a literal.
 *
 * Lane FO made both editable and deliberately kept them byte for byte as the
 * defaults, so applying its package changed nothing. This is the other half:
 * where a claim can be DERIVED it is derived, and where it cannot be it is left
 * to the owner and nothing is invented.
 *
 * ── WHAT IS NOT ASSERTED HERE ───────────────────────────────────────────────
 *
 * Not "the token is in the constant". A guard over DEFAULT_SLIDES would pass
 * with the substitution deleted. Every assertion below reads the HERO BAND of a
 * rendered page and compares it with a figure read independently — Brand::count()
 * off the database, and the `min_amount` the fixture wrote onto the shipping
 * method — so the page and the shop are compared with each other rather than
 * either with itself.
 *
 * ── EVERY TEST BELOW WAS RUN AGAINST THE UNFIXED TREE ───────────────────────
 *
 * The mutations are listed in docs/FR-HOMEPAGE-ORDER.md.
 */

use App\Models\Brand;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\HomepageContent;
use App\Services\SettingsService;
use App\Support\Money;

beforeEach(function () {
    // Every cache these figures live behind, as SitewideDeliveryClaimsTest
    // records: a forever-cache, a per-process memo, Setting::map()'s own static
    // and the brand tally's 900-second entry.
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
    Illuminate\Support\Facades\Cache::forget('kbb.home.brandcount');
});

/** A free-shipping method for the UAE at $minAmount fils, and a flat rate beside it. */
function frUaeFreeOver(?int $minAmount): void
{
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    if ($minAmount !== null) {
        ShippingMethod::create([
            'shipping_zone_id' => $uae->id, 'type' => 'free_shipping',
            'title' => 'Free delivery', 'cost' => 0, 'min_amount' => $minAmount,
            'enabled' => true, 'position' => 1,
        ]);
    }
}

/** The hero band of a page fetched as a visitor the shop believes is in $country. */
function frHeroAs(?string $country = 'AE'): string
{
    SettingsService::forgetMemo();
    Illuminate\Support\Facades\Cache::forget('kbb.home.brandcount');

    $t = test();

    if ($country !== null) {
        $t = $t->withHeader('CF-IPCountry', $country);
    }

    $html = $t->get('/')->getContent();
    $at = strpos($html, 'id="slider"');

    if ($at === false) {
        return '';
    }

    $end = strpos($html, 'class="sdots"', $at);

    return substr($html, $at, ($end === false ? strlen($html) : $end) - $at);
}

/* ────────────────────────────────────────────────────── §1 the brand count */

it('prints the brand count the strip below it counts, not a number typed into a slide', function () {
    $real = Brand::query()->count();

    // The fixture has to have brands for this to be saying anything.
    expect($real)->toBeGreaterThan(0);

    $hero = frHeroAs();

    expect($hero)->toContain($real . ' brands · sourced direct');

    // And the figure the shop was shipping instead is gone from the band.
    expect($hero)->not->toContain('93 brands');
});

it('follows the catalogue when a brand is added', function () {
    $before = Brand::query()->count();

    expect(frHeroAs())->toContain($before . ' brands · sourced direct');

    Brand::create(['name' => 'A New Brand', 'slug' => 'a-new-brand-' . uniqid()]);

    // The whole point of deriving it: the sentence moves because the shop did,
    // and nobody had to ship a package to move it.
    expect(frHeroAs())->toContain(($before + 1) . ' brands · sourced direct');
});

it('says nothing rather than nought when the catalogue holds no brands', function () {
    Brand::query()->delete();

    $hero = frHeroAs();

    // "0 brands · sourced direct" is arithmetically true and reads as a broken
    // page. The eyebrow has no box of its own and collapses to nothing, which
    // is the rule the delivery band inside this same hero already follows.
    expect($hero)->not->toContain('brands · sourced direct');

    // The rest of the slide is untouched — a dropped line is not a dropped slide.
    expect($hero)->toContain('The authentic<br>K-beauty store');
    expect($hero)->toContain('Shop all brands');
});

/* ───────────────────────────────────────────── §2 the free-delivery figure */

it('quotes the threshold Store → Shipping enforces, and moves when it does', function () {
    frUaeFreeOver(19900);

    // The figure the slide used to have typed into it. It is asserted here not
    // because 199 is special but because this is the shop that ships today:
    // deriving it must not move the page for a shop that changed nothing.
    expect(frHeroAs('AE'))->toContain('Free delivery across the UAE over AED 199.');

    // The zone table is behind a forever-cache, exactly as the owner's own
    // save on Store → Shipping has to clear it.
    ShippingMethod::where('type', 'free_shipping')->update(['min_amount' => 30000]);
    \App\Services\ShippingService::flushZones();

    // And the half that was a plain bug: the owner raises the real number and
    // the hero used to go on advertising the old one.
    $hero = frHeroAs('AE');

    expect($hero)->toContain('Free delivery across the UAE over AED 300.');
    expect($hero)->not->toContain('AED 199');
});

it('drops the sentence, and only that sentence, where the shop has no such rule', function () {
    frUaeFreeOver(null);

    $hero = frHeroAs('AE');

    // Nothing is invented to fill the gap: no threshold has been configured, so
    // there is no figure that is true and no softer version of the claim.
    expect($hero)->not->toContain('Free delivery across the UAE over');

    // The owner's other sentence on the same slide survives — it is true
    // everywhere and deleting it would be this change taking copy it was not
    // asked to take.
    expect($hero)->toContain('Split any order into four.');
    expect($hero)->toContain('Pay later,<br>delivered in 1–3 days');
});

it('answers per visitor, because the threshold is per zone', function () {
    frUaeFreeOver(19900);

    // A second zone with a different threshold, which is what production runs.
    $gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);

    foreach (['SA', 'KW', 'QA'] as $code) {
        ShippingZoneLocation::create(['shipping_zone_id' => $gulf->id, 'type' => 'country', 'code' => $code]);
    }

    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'flat_rate',
        'title' => 'Shipping Charges', 'cost' => 15000, 'enabled' => true, 'position' => 0,
    ]);
    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'free_shipping',
        'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 160000, 'enabled' => true, 'position' => 1,
    ]);

    expect(frHeroAs('AE'))->toContain('AED 199');
    expect(frHeroAs('SA'))->toContain('AED 1,600');
});

/* ───────────────────────────────── §3 the token is the owner's, not a rule */

it('leaves the wording around the figure editable, and prints no markup', function () {
    frUaeFreeOver(19900);

    $content = app(HomepageContent::class);

    // The token is the FIGURE only. An owner rewriting the sentence around it
    // keeps the derived number; deleting the token leaves a sentence with no
    // number in it, which is also a thing an owner is allowed to want.
    $slides = $content->slides();

    expect($slides[1]['kicker'])->toBe(Brand::query()->count() . ' brands · sourced direct');

    // Money::format() returns a <span>; the supporting line is printed through
    // {{ }}, so markup here would appear on the banner as text. This is the
    // assertion that the plain form is used.
    expect($slides[2]['text'])->not->toContain('<span');
    expect($slides[2]['text'])->not->toContain('woocommerce-Price-amount');
});

it('hands the editor the token and not the figure, so the box stays editable', function () {
    // editable() is what the screen loads into its boxes. If substitution
    // happened there, an owner who saved without touching the eyebrow would
    // freeze today's count into the setting — the exact defect this change
    // removes, reintroduced by the screen.
    $editable = app(HomepageContent::class)->editable();

    expect($editable[1]['kicker'])->toBe('{brands} brands · sourced direct');
    expect($editable[2]['text'])->toContain('{free_from}');

    // And the payload says what the tokens mean and what they stand at, so the
    // screen can explain them without a second copy of either.
    $payload = app(HomepageContent::class)->payload();

    expect($payload['tokens'])->toHaveKey('{brands}');
    expect($payload['tokens'])->toHaveKey('{free_from}');
    expect($payload['figures']['{brands}'])->toBe((string) Brand::query()->count());
});

it('counts the brands once for the page, not once per reader', function () {
    // The eyebrow and the brand strip's own tally are the same number by
    // construction: one cache key, one query. A second COUNT(*) here is how the
    // page came to hold two answers in the first place.
    $controller = (string) file_get_contents(base_path('app/Http/Controllers/Store/HomeController.php'));

    expect($controller)->toContain('HomepageContent::brandTotal()');
    expect($controller)->not->toContain("Cache::remember('kbb.home.brandcount'");

    // And the key stays the one flushCache() forgets, or a catalogue import
    // would leave the figure stale for fifteen minutes.
    expect($controller)->toContain('kbb.home.brandcount');
});
