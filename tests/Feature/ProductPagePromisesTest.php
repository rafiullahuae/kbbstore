<?php

declare(strict_types=1);

/**
 * Lane CT — the product page stops promising delivery to the wrong country.
 *
 * The checkout was repaired first, the home page second. The product page was
 * the last screen still making delivery promises without asking where the
 * shopper is standing, and it was making two of them.
 *
 *   1. THE TRUST CHIP. store/product.blade.php printed `trust_delivery_text` —
 *      one global string, no country check of any kind — as a chip under Add to
 *      cart. It ships blank, so nothing false was on the page yet; but the
 *      admin screen that writes it suggests a UAE sentence, and the instant the
 *      owner typed one, every visitor on earth would have read it. That is
 *      precisely the defect Lane CO had just removed from the home page, armed
 *      and waiting one keystroke behind it.
 *
 *      A SINGLE GLOBAL STRING CANNOT BE MADE COUNTRY-AWARE. It can only ever be
 *      true of one country and the shop has no way of knowing which. So the
 *      chip is not gated, it is re-sourced: it now renders
 *      App\Support\DeliveryLine::here(), the same one reader the home page and
 *      the checkout already use, and `trust_delivery_text` is gone. One screen
 *      writes the sentence — Store → Delivery & Shipping → Delivery lines — and
 *      every surface of the shop agrees about it by construction.
 *
 *   2. THE DISPATCH COUNTDOWN. "Order within 4h 12m for delivery by Tue, 30
 *      Jun" is two claims wearing one sentence, and only one of them is about
 *      the warehouse:
 *
 *        - the countdown and the dispatch date come from `dispatch_cutoff_hour`
 *          and the Friday rule. When the parcel LEAVES is a fact about the
 *          shop's own working week and is true whatever the destination.
 *        - the arrival date is that dispatch date plus `dispatch_days`, ONE
 *          GLOBAL NUMBER whose default of 2 describes the UAE. Added to a
 *          Riyadh order it is an invented transit time, presented to the
 *          shopper as a date.
 *
 *      So the arrival half is shown to the shop's own country and to nobody
 *      else, and everyone else keeps the half that is true. NOTHING IS INVENTED
 *      TO FILL THE GAP: no Saudi or Kuwaiti transit time has been measured, and
 *      a plausible-looking number written in here would be the same untruth
 *      this whole line of work exists to remove.
 *
 *      AND A DELIVERY LINE IS NOT A TRANSIT TIME. The tempting rule — "show the
 *      arrival date wherever the owner has written a delivery line" — is wrong
 *      and is pinned against below. A row reading "Delivered across Saudi
 *      Arabia" says nothing about how many days it takes, so borrowing
 *      `dispatch_days` on the strength of it would invent the very number this
 *      lane refuses to invent.
 *
 * THE CLASS-NAME TRAP, as GeoDeliveryLineTest and ShopperPathTruthTest both
 * record: kbb-product.css is pulled into this page, so a bare search of the
 * rendered HTML for `ti` or `deliver` matches a stylesheet rule whether or not
 * any element carries the class. Every assertion here matches an ELEMENT with
 * preg_match/preg_match_all, or matches the exact words a shopper would read.
 */

use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\SettingsService;
use App\Support\DeliveryLine;
use App\Support\Money;
use App\Models\Product;
use Illuminate\Support\Str;

beforeEach(function () {
    /*
     * Every cache these settings live behind, dropped before each case.
     * SettingsService holds a forever-cache AND a per-process memo, and
     * Setting::map() holds a second static of its own — the trap CLAUDE.md
     * records. Without this a value written by one case is read back stale by
     * the next, which reads as a failure belonging to neither.
     */
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();

    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    $gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);
    foreach (['SA', 'KW', 'QA', 'BH', 'OM'] as $code) {
        ShippingZoneLocation::create(['shipping_zone_id' => $gulf->id, 'type' => 'country', 'code' => $code]);
    }
    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'flat_rate',
        'title' => 'Shipping Charges', 'cost' => 15000, 'enabled' => true, 'position' => 0,
    ]);
});

/** Settings only ever through the service — it holds a forever-cache AND a per-process memo. */
function ppSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
}

function ppProduct(): Product
{
    return Product::create([
        'slug' => 'pp-' . Str::random(8),
        'name' => 'Rice Probiotics Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ]);
}

/** The product page as seen from one country, or from nowhere at all when null. */
function ppPage(?string $country): string
{
    $request = test();

    if ($country !== null) {
        $request = $request->withHeader('CF-IPCountry', $country);
    }

    return $request->get(ppProduct()->url())->assertOk()->getContent();
}

/**
 * The chips in the trust row, as a shopper reads them.
 *
 * Matched off the ELEMENT, because `ti` and `trust` are both class names this
 * page's own inlined stylesheet mentions.
 *
 * @return array<int, string>
 */
function ppChips(string $html): array
{
    $row = [];

    if (preg_match('/<div class="[^"]*\btrust\b[^"]*">(.*?)<\/div>\s*<div class="[^"]*\bpaychips\b/s', $html, $row) !== 1) {
        return [];
    }

    $chips = [];
    preg_match_all('/<div class="ti">(.*?)<\/div>/s', $row[1], $chips);

    return array_map(
        static fn (string $chip) => trim(html_entity_decode(strip_tags($chip))),
        $chips[1]
    );
}

/** The dispatch countdown line, or null when the page does not render one. */
function ppCountdown(string $html): ?string
{
    $m = [];

    return preg_match('/<div class="[^"]*\bdeliver\b[^"]*">(.*?)<\/div>/s', $html, $m) === 1
        ? trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]))))
        : null;
}

/*
|------------------------------------------------------------------------------
| 1. The trust chip belongs to the visitor's country
|------------------------------------------------------------------------------
*/

it('does not put a UAE delivery promise in the trust row of a shopper in Saudi Arabia', function () {
    /*
     * The defect this lane exists for. `delivery_default_text` is the owner's
     * own sentence about the United Arab Emirates; there is no row for Saudi
     * Arabia, so the only true thing the shop can say to this shopper about
     * delivery is nothing at all.
     */
    $chips = ppChips(ppPage('SA'));

    foreach ($chips as $chip) {
        expect(str_contains($chip, 'UAE'))
            ->toBeFalse('A UAE delivery promise was shown in the trust row to a shopper detected in Saudi Arabia: ' . $chip);
    }
});

it('shows the UAE delivery line in the trust row of a UAE shopper', function () {
    // The other half. Removing the sentence everywhere would be its own defect
    // — it is true of the country it names, and the home page and the checkout
    // already show this same shopper this same sentence.
    $chips = ppChips(ppPage('AE'));

    expect(implode(' | ', $chips))->toContain('1–3 days fast delivery all over UAE');
});

it('shows a Gulf shopper the line the owner wrote for his own country', function () {
    // The mechanism, proved with the owner's words rather than an invented
    // transit time. Nothing in the shipped code says this sentence.
    ppSet(DeliveryLine::SETTING, [['country' => 'SA', 'text' => 'Delivered across Saudi Arabia']]);

    $chips = ppChips(ppPage('SA'));

    expect(str_contains(implode(' | ', $chips), 'Delivered across Saudi Arabia'))
        ->toBeTrue('A delivery line the owner wrote for Saudi Arabia never reached the product page.');
});

it('renders no delivery chip element at all when there is nothing true to say', function () {
    /*
     * A claim with nothing behind it is REMOVED, not softened. The chip is not
     * an empty box with a truck in it — the element is absent, exactly as the
     * two unbacked literals Lane CM deleted are absent.
     *
     * Counted rather than searched for: the row's other chips are unaffected,
     * so the evidence is that a Saudi shopper gets strictly fewer chips than a
     * UAE one and that the missing one is the delivery one.
     */
    $gulf = ppChips(ppPage('SA'));
    $home = ppChips(ppPage('AE'));

    expect(count($gulf))->toBe(count($home) - 1, 'The delivery chip was blanked rather than removed.');
    expect($gulf)->toContain('100% authentic');
    expect(str_contains(implode(' | ', $gulf), 'delivery'))
        ->toBeFalse('An empty delivery chip is still on the page: ' . implode(' | ', $gulf));
});

it('keeps the claims the shop does back', function () {
    // Authenticity is what this shop is and the pay-later chip is read off the
    // gateways it actually offers. Taking the whole row out would have been its
    // own defect.
    $chips = ppChips(ppPage('SA'));

    expect($chips)->toContain('100% authentic');
    expect(implode(' | ', $chips))->toContain('Tabby');
});

it('gives the product page and the home page the same answer for the same visitor', function () {
    /*
     * The point of having one reader. Two screens of one shop cannot be allowed
     * to disagree about where this shopper is standing — that contradiction is
     * the whole reason DeliveryLine and ShopperCountry exist, and a third
     * surface with a setting of its own would have reintroduced it.
     */
    ppSet(DeliveryLine::SETTING, [['country' => 'KW', 'text' => 'Delivered across Kuwait']]);

    $product = ppPage('KW');
    $home = test()->withHeader('CF-IPCountry', 'KW')->get('/')->assertOk()->getContent();

    expect(str_contains(implode(' | ', ppChips($product)), 'Delivered across Kuwait'))
        ->toBeTrue('The product page does not say what the home page says to the same Kuwaiti visitor.');
    expect(str_contains($home, 'Delivered across Kuwait'))->toBeTrue();
});

it('has no second screen left in which to write the product page a delivery sentence', function () {
    /*
     * The duplication this decision removes, pinned so it cannot come back.
     * `trust_delivery_text` was a second place to write "the delivery line",
     * and a value left in the settings table by an older build must not be able
     * to speak over the per-country one — least of all to the wrong country.
     */
    ppSet('trust_delivery_text', 'Next-day delivery, wherever you are');

    $chips = ppChips(ppPage('SA'));

    expect(str_contains(implode(' | ', $chips), 'wherever you are'))
        ->toBeFalse('A stale global delivery string is still printed at every shopper: ' . implode(' | ', $chips));
});

/*
|------------------------------------------------------------------------------
| 2. The dispatch countdown promises arrival only where arrival is known
|------------------------------------------------------------------------------
*/

it('does not give a shopper outside the shop own country a delivery date', function () {
    /*
     * `dispatch_days` is one number and it describes the shop's own country.
     * Adding it to a Riyadh order produces a date nobody has measured, printed
     * in bold as though it had been.
     */
    $line = ppCountdown(ppPage('SA'));

    expect($line)->not->toBeNull('The countdown vanished entirely rather than dropping its arrival half.');
    expect(str_contains((string) $line, 'for delivery by'))
        ->toBeFalse('A shopper in Saudi Arabia was given an arrival date built from the UAE transit time: ' . $line);
});

it('still tells that shopper when the parcel leaves, which is a fact about the warehouse', function () {
    // Silence was not the only option here, and truth is not served by throwing
    // away the half of the sentence that is true. The cutoff hour and the
    // Friday rule describe the shop's own working week.
    $line = ppCountdown(ppPage('SA'));

    expect(str_contains((string) $line, 'Order within'))->toBeTrue();
    expect(str_contains((string) $line, 'to ship on'))
        ->toBeTrue('The dispatch half of the countdown went with the arrival half: ' . $line);
});

it('keeps the delivery date exactly as it is for the shop own country', function () {
    // Today's behaviour for the UAE is the default, and it is not a regression
    // dressed up as a repair.
    $line = ppCountdown(ppPage('AE'));

    expect(str_contains((string) $line, 'Order within'))->toBeTrue();
    expect(str_contains((string) $line, 'for delivery by'))
        ->toBeTrue('The UAE lost the arrival date it is entitled to: ' . $line);
});

it('keeps the delivery date when no geo signal arrives at all', function () {
    /*
     * NO GEO SOURCE IS A FULLY SUPPORTED STATE, and on this host it may be the
     * only state: whether production ever receives CF-IPCountry has not been
     * established. ShopperCountry answers with `store_country` and source
     * DEFAULT, so the shop falls back to describing itself — which is exactly
     * what it did before this lane touched anything.
     */
    $line = ppCountdown(ppPage(null));

    expect(str_contains((string) $line, 'for delivery by'))
        ->toBeTrue('A visitor with no geo signal lost the behaviour this page had before: ' . $line);
});

it('follows the store country rather than a hard-coded UAE', function () {
    /*
     * The rule is "the one country `dispatch_days` describes", not "the United
     * Arab Emirates". A shop that moves to Riyadh must take its arrival date
     * with it and stop showing it in Dubai.
     */
    ppSet('store_country', 'SA');

    expect(str_contains((string) ppCountdown(ppPage('SA')), 'for delivery by'))
        ->toBeTrue('The shop moved to Saudi Arabia and its own shoppers lost the arrival date.');
    expect(str_contains((string) ppCountdown(ppPage('AE')), 'for delivery by'))
        ->toBeFalse('The UAE kept an arrival date after the shop stopped being a UAE shop.');
});

it('does not read a delivery line as permission to invent a transit time', function () {
    /*
     * THE TEMPTING RULE THAT IS WRONG. "Show the arrival date wherever the
     * owner has written a delivery line" looks like the careful option and is
     * not: a row reading "Delivered across Saudi Arabia" records a sentence,
     * not a number of days. Borrowing `dispatch_days` on the strength of it
     * would print a UAE transit time as a Saudi date — the exact untruth this
     * lane removed from the chip six cases above.
     */
    ppSet(DeliveryLine::SETTING, [['country' => 'SA', 'text' => 'Delivered across Saudi Arabia']]);

    $line = ppCountdown(ppPage('SA'));

    expect(str_contains((string) $line, 'for delivery by'))
        ->toBeFalse('A delivery sentence was taken as a measured transit time: ' . $line);
});

it('lets the owner switch the whole countdown off, wherever the shopper is', function () {
    // The module gate is untouched and still means what it meant.
    app(SettingsService::class)->setModule('dispatch_cutoff', false);

    expect(ppCountdown(ppPage('AE')))->toBeNull('The dispatch countdown ignored its own off switch.');
    expect(ppCountdown(ppPage('SA')))->toBeNull('The dispatch countdown ignored its own off switch.');
});

it('reads the shopper time zone cookie when no header arrives', function () {
    /*
     * The tier that may be the only one this host ever gets. `kbb_tz` is
     * written by the browser itself and is exempt from cookie encryption, so it
     * arrives as plain text; ShopperCountry delegates it to
     * ExtendedDelivery::detect().
     */
    $html = test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie('kbb_tz', 'Asia/Riyadh')
        ->get(ppProduct()->url())->assertOk()->getContent();

    expect(str_contains((string) ppCountdown($html), 'for delivery by'))
        ->toBeFalse('A shopper whose browser says Riyadh was handed the UAE arrival date.');

    foreach (ppChips($html) as $chip) {
        expect(str_contains($chip, 'UAE'))
            ->toBeFalse('A shopper whose browser says Riyadh was handed the UAE delivery promise: ' . $chip);
    }
});
