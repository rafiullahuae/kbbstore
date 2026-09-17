<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\SettingsService;
use App\Services\Translation\InterfaceStrings;
use App\Support\Money;

/**
 * Lane FB — the last free-delivery figure typed into a template, and the reason
 * the four sweeps before this one did not find it.
 *
 * ── WHAT WAS THERE ──────────────────────────────────────────────────────────
 *
 * resources/views/store/account/login.blade.php, in the aside beside the
 * sign-in form:
 *
 *     <p class="authside-note">Free delivery on orders over د.إ150.</p>
 *
 * Not merely hard-coded. WRONG, and wrong against this shop's own answer: the
 * free-delivery method production runs carries min_amount 19900, and every
 * other surface that states the threshold — the announcement bar, the home
 * ticker, the trust row, window.KBB.freeShip — resolves it through
 * ShippingService::thresholdHere() and says 199. A shopper who read the sign-in
 * page and then the home page was told two different numbers by the same shop,
 * and the smaller one was on the page that asks for their password.
 *
 * ── WHY LANE CZ'S SWEEP MISSED IT ───────────────────────────────────────────
 *
 * SitewideDeliveryClaimsTest is thorough about the surfaces it knows: the bar,
 * the ticker, the delivery band, the about stats, the trust row. It enumerates
 * them. Nothing in it walks the templates asking "who else states a threshold",
 * so a fifth surface nobody had thought of was never going to be reported by
 * it. That is why the last case below is a SWEEP rather than a sixth
 * enumeration: the next one of these should fail a test on the day it is typed,
 * without anybody having to remember this file exists.
 *
 * ── AND WHY IT SURVIVED THE INTERFACE-STRINGS LANE TOO ──────────────────────
 *
 * Lane EU keyed it. Correctly, by its own remit — it moved English into
 * InterfaceStrings without changing a byte of it — but the byte it moved was a
 * figure, so `account.aside_free_delivery` became
 *
 *     'Free delivery on orders over د.إ150.'
 *
 * as the ENGLISH SOURCE of a translatable string. That is a worse resting place
 * than the template: MachineTranslationRunner would have carried the wrong
 * number into Arabic and the shop would have stated a false threshold in two
 * languages, each confirming the other. The third case below is the guard
 * against that specifically, and it reads the strings table rather than the
 * views because that is where such a sentence now lives.
 *
 * ── THE ASIDE IS OFF BY DEFAULT, WHICH IS NOT A DEFENCE ─────────────────────
 *
 * AccountPanel::FIELDS has 'form_aside' => false, so the block renders only
 * once the owner switches "Reasons panel" on in Store → Account. It is the
 * posture Lane CZ found the announcement bar in and repaired anyway, for the
 * reason recorded there: a claim one switch behind the page is still a claim,
 * and the switch is the owner's to flip on a day nobody is reading this file.
 * Every case below turns it on, because that is the page the owner gets.
 *
 * NOTHING HERE INVENTS A FIGURE. 19900 and 160000 are the two thresholds
 * SitewideDeliveryClaimsTest already runs as production's, written here into
 * the shop's own shipping configuration and then read back off the page.
 */
beforeEach(function () {
    // The settings layer caches three deep — see CLAUDE.md on Setting::map()'s
    // process-level static, which flushMap() does not clear on its own.
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();

    app(SettingsService::class)->set('store_country', 'AE');

    // The owner's switch. Without it the aside — and the claim — do not render.
    app(SettingsService::class)->set('account_panel', ['form_aside' => true]);

    SettingsService::forgetMemo();
    Setting::flushMap();
});

/** The shop's real zones, with the two thresholds production runs. */
function fbZones(bool $uaeFree = true): void
{
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    if ($uaeFree) {
        ShippingMethod::create([
            'shipping_zone_id' => $uae->id, 'type' => 'free_shipping',
            'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1,
        ]);
    }

    $gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);
    foreach (['SA', 'KW', 'QA', 'BH', 'OM'] as $code) {
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
}

/** The sign-in page as a visitor the shop believes is in $country. */
function fbSignIn(?string $country = null): string
{
    $test = test();

    if ($country !== null) {
        $test = $test->withHeader('CF-IPCountry', $country);
    }

    return $test->get('/my-account')->assertOk()->getContent();
}

/**
 * The aside's delivery line as a shopper reads it — tags stripped, entities
 * resolved, whitespace collapsed. Null when the paragraph is not on the page.
 *
 * SCOPED ON PURPOSE. Asserting toContain('199') against the whole document is
 * not an assertion: '199' occurs in a Vite asset hash, a hex colour and an
 * inline SVG path, so the test passes on a page that never mentions delivery.
 * Every case below reads THIS string, which is the sentence and nothing else.
 */
function fbNote(?string $country = null): ?string
{
    if (! preg_match('/<p class="authside-note">(.*?)<\/p>/s', fbSignIn($country), $m)) {
        return null;
    }

    $text = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Money::format() wraps the figure in a bidi isolate; strip_tags leaves the
    // invisible marks behind and they would sit between the symbol and the
    // digits in any comparison.
    $text = preg_replace('/[\x{200E}\x{200F}\x{2066}-\x{2069}]/u', '', $text);

    return trim(preg_replace('/\s+/u', ' ', $text));
}

it('quotes the shop\'s own free-delivery threshold in the sign-in aside', function () {
    fbZones();

    $note = fbNote('AE');

    // The aside is on the page at all — otherwise every assertion below passes
    // for the wrong reason.
    expect($note)->not->toBeNull('The sign-in aside did not render, so this case proves nothing.');

    expect($note)
        ->toContain('199')
        ->and($note)->not->toContain('150');
});

it('follows the shopper\'s own zone rather than restating one country\'s terms', function () {
    /*
     * The figure the sign-in page quotes has to be the figure that shopper
     * would actually get. A literal cannot do this at all, which is the whole
     * argument for driving it from thresholdHere(): the Gulf zone's free
     * delivery starts at 1600, not 199, and a Riyadh shopper reading "over 199"
     * on the sign-in page is being quoted Dubai's terms — the exact defect Lane
     * CZ repaired in the announcement bar.
     */
    fbZones();

    $note = fbNote('SA');

    expect($note)->not->toBeNull('The sign-in aside did not render, so this case proves nothing.');

    expect($note)
        ->toContain('1,600')
        ->and($note)->not->toContain('199');
});

it('says nothing about free delivery when the shopper has no free delivery to be told about', function () {
    /*
     * thresholdHere() returns null for a zone with no free_shipping method, and
     * a sentence built by dropping that into "Free delivery on orders over
     * :amount." would render "Free delivery on orders over ." — a promise with
     * its number missing, which is worse than the wrong number because it still
     * reads as a promise. partials/announcement.blade.php guards this with
     * @if ($threshold !== null); the guard is copied rather than reinvented.
     */
    fbZones(uaeFree: false);

    // The aside itself still renders — it is the delivery sentence inside it
    // that must be absent, not the whole panel.
    expect(fbSignIn('AE'))->toContain('authside');

    expect(fbNote('AE'))->toBeNull();
});

it('leaves no free-delivery figure typed into the interface strings', function () {
    /*
     * THE SWEEP, and the case that generalises. Lane EU moved this sentence out
     * of the Blade and into InterfaceStrings, so the strings table is now a
     * second place a typed threshold can live — and the worse of the two,
     * because a figure in an English SOURCE string gets machine-translated into
     * every other language as part of the sentence around it.
     *
     * Any string that promises free delivery must carry its figure as a
     * placeholder. A digit inside such a sentence is a figure nobody can change
     * from Store → Shipping.
     */
    $offenders = [];

    foreach (InterfaceStrings::flat() as $key => $english) {
        if (! preg_match('/free\s+(delivery|shipping)/i', $english)) {
            continue;
        }

        // A digit anywhere in a free-delivery sentence is a threshold typed in.
        // Arabic-Indic digits too — the literal this lane removed was written
        // with the د.إ symbol and could as easily have been written ١٥٠.
        if (preg_match('/[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $english)) {
            $offenders[$key] = $english;
        }
    }

    expect($offenders)->toBe([], sprintf(
        "A free-delivery sentence carries a figure typed into it:\n\n%s\n\n"
        . 'The threshold is the owner\'s, set on the free-shipping method and read through '
        . 'ShippingService::thresholdHere(). Put :amount in the string and pass the formatted '
        . 'figure in, the way store.delivery.free_over already does.',
        implode("\n", array_map(
            fn (string $k, string $v): string => "  {$k} => \"{$v}\"",
            array_keys($offenders),
            $offenders
        ))
    ));
});
