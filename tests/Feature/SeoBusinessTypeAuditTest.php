<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\BusinessAddress;
use App\Support\SeoAudit;

/**
 * =============================================================================
 * THE BUSINESS TYPE AND THE ADDRESS CAN DISAGREE, AND NOTHING SAID SO
 * =============================================================================
 *
 * The owner's shop is online-only, open all hours, with no premises. Checked
 * against the code rather than taken on trust, and the emitters are already
 * right: `BusinessAddress::PLACE_TYPES` is ['Store', 'LocalBusiness'] only, so
 * `geo` and `openingHoursSpecification` are gated off for an `OnlineStore` —
 * 24/7 needs no setting at all, because opening hours belong on a Place and an
 * online shop is not one. A shop with no address emits no `address` key, and a
 * half-filled one is refused outright. Nothing there needed building.
 *
 * WHAT WAS MISSING is that the two screens can be set so they contradict each
 * other and no screen anywhere says so:
 *
 *   `Store` or `LocalBusiness` with no usable address — an Organization node
 *   claiming premises it has not described. Google places a local business by
 *   its address; there is nothing to place.
 *
 *   coordinates typed in under `Organization` or `OnlineStore` — dropped in
 *   silence, because those are not Places. The owner is looking at a latitude
 *   and longitude that no page emits.
 *
 * Both are now a finding on Store → SEO & Meta → SEO audit. Nothing is emitted
 * differently; the audit just says it out loud.
 */

function sbtSettings(array $values): void
{
    // Every key this check reads, cleared first, so one case cannot inherit
    // another's address. SettingsService writes '' rather than deleting.
    foreach ([
        'org_type' => '', 'store_street' => '', 'store_locality' => '', 'store_country' => '',
        'store_region' => '', 'store_postcode' => '', 'store_latitude' => '', 'store_longitude' => '',
    ] as $key => $blank) {
        Setting::updateOrCreate(['key' => $key], ['value' => $values[$key] ?? $blank, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
}

function sbtFinding(): array
{
    return SeoAudit::run()['findings']['business_type_mismatch'];
}

it('says nothing about an online shop with nothing filled in, which is this shop', function () {
    /*
     * Rule 1, and the state the owner is actually in: online-only, no premises,
     * no address typed. The audit must be silent — there is no fault here, and a
     * finding against a correct shop is a finding he learns to ignore.
     */
    sbtSettings(['org_type' => 'OnlineStore']);

    expect(sbtFinding()['count'])->toBe(0);
});

it('says nothing when the type is left at the shipped default either', function () {
    /*
     * `org_type` has a documented default of 'Organization' in
     * SeoSettings::DEFAULTS, reached when the row is absent or blank. Not a
     * Place, nothing typed, so nothing to report. This is the state of every
     * shop that has never opened the Organization card.
     */
    sbtSettings([]);

    expect(sbtFinding()['count'])->toBe(0);
});

it('reports a shopfront type with no address, which publishes a claim it cannot support', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE TEST THAT IS RED WITHOUT THE CHECK
     * ════════════════════════════════════════════════════════════════════════
     *
     * MUTATION NOTE: delete the `self::scanBusinessType($findings)` call from
     * SeoAudit::run() and all three reporting cases here read 0. Verified.
     *
     * Both Place types, because the muddle is the same muddle and a check that
     * only knew about one of them would be worse than none.
     */
    foreach (BusinessAddress::PLACE_TYPES as $type) {
        sbtSettings(['org_type' => $type]);

        $finding = sbtFinding();

        expect($finding['count'])->toBe(1, $type.' with no address must be reported')
            ->and($finding['samples'][0]['name'] ?? null)->toBe($type)
            ->and($finding['samples'][0]['detail'] ?? '')->toContain('no usable street address');
    }
});

it('treats a half-filled address as no address, by the same rule the emitted node uses', function () {
    /*
     * `BusinessAddress::postal()` is asked rather than the three columns, so
     * "half-filled" cannot mean two different things in two places. Its own
     * header is the argument: a node that says {"addressCountry":"AE"} and
     * nothing else is WORSE than silence, because it tells a search engine the
     * address has been published and that it is "the UAE".
     *
     * The third case is the one a second implementation would get wrong: a
     * complete-looking address whose country code is not one this application
     * recognises. postal() refuses it, so the audit must too.
     */
    foreach ([
        'street only' => ['store_street' => 'Shop 4, Al Wasl Road'],
        'no street' => ['store_locality' => 'Dubai', 'store_country' => 'AE'],
        'country not recognised' => [
            'store_street' => 'Shop 4, Al Wasl Road', 'store_locality' => 'Dubai', 'store_country' => 'ZZ',
        ],
    ] as $label => $address) {
        sbtSettings(array_merge(['org_type' => 'Store'], $address));

        expect(BusinessAddress::postal(\App\Services\Seo\SeoSettings::map()))->toBeNull($label)
            ->and(sbtFinding()['count'])->toBe(1, $label.' is not an address, so the claim is unsupported');
    }
});

it('says nothing once a Store has a real address', function () {
    /*
     * The clean shopfront case. If this ever reports, the check is telling a
     * shop with premises and an address that it has a problem, which would be
     * the fastest way to get the whole audit screen distrusted.
     */
    sbtSettings([
        'org_type' => 'Store',
        'store_street' => 'Shop 4, Al Wasl Road',
        'store_locality' => 'Dubai',
        'store_country' => 'AE',
    ]);

    expect(sbtFinding()['count'])->toBe(0);
});

it('reports coordinates that no page can publish, and only when they are complete', function () {
    /*
     * The other direction. `geo()` wants BOTH halves — a latitude on its own is
     * not a location — so a half-typed coordinate is not yet a mistake worth
     * reporting, and the audit follows the emitter rather than keeping its own
     * rule. Same single authority as the address case above.
     */
    sbtSettings(['org_type' => 'OnlineStore', 'store_latitude' => '25.2048']);

    expect(sbtFinding()['count'])->toBe(0, 'half a coordinate is not yet a coordinate');

    sbtSettings(['org_type' => 'OnlineStore', 'store_latitude' => '25.2048', 'store_longitude' => '55.2708']);

    $finding = sbtFinding();

    expect($finding['count'])->toBe(1)
        ->and($finding['samples'][0]['detail'] ?? '')->toContain('map coordinates are filled in');

    // And the same coordinates under a Place type are fine — they are published.
    sbtSettings([
        'org_type' => 'Store', 'store_latitude' => '25.2048', 'store_longitude' => '55.2708',
        'store_street' => 'Shop 4, Al Wasl Road', 'store_locality' => 'Dubai', 'store_country' => 'AE',
    ]);

    expect(sbtFinding()['count'])->toBe(0);
});

it('does not flag an address on an online shop, because that is a valid shape', function () {
    /*
     * DELIBERATELY NOT A FINDING. `address` and `telephone` are properties of
     * Organization, so a company with a registered office and no shopfront is
     * real, valid and common — this shop is online-only and may still want its
     * trading address on the node. Flagging it would be inventing a rule
     * schema.org does not have, which is the same error as publishing a policy
     * nobody stated.
     */
    sbtSettings([
        'org_type' => 'OnlineStore',
        'store_street' => 'Office 1204, Business Bay',
        'store_locality' => 'Dubai',
        'store_country' => 'AE',
    ]);

    expect(sbtFinding()['count'])->toBe(0);
});

it('counts at most one, and does not inflate the number of URLs scanned', function () {
    /*
     * A shop is in one state, so two findings against the same pair of screens
     * would read as two problems. And `total` is the first number on the screen
     * — "N indexable URLs scanned" — so a finding about the SHOP must not be
     * added to it. The same argument the legacy-address check already makes.
     *
     * MUTATION NOTE, AND IT CAME BACK GREEN: dropping the `return` from the
     * first branch changes nothing, and running it is how I found out why. The
     * two branches are guarded on `$isPlace` and `! $isPlace`, so they are
     * mutually exclusive whatever follows — the `return` is defensive and the
     * "at most one" property comes from that pair of conditions. The shop set up
     * below is in both muddles at once (a Place type, coordinates, no address)
     * and still counts 1, which is the property worth pinning; the mutation that
     * IS red is removing the scan altogether, above.
     */
    sbtSettings(['org_type' => 'LocalBusiness', 'store_latitude' => '25.2048', 'store_longitude' => '55.2708']);

    $report = SeoAudit::run();

    expect($report['findings']['business_type_mismatch']['count'])->toBe(1)
        ->and($report['scanned'])->not->toHaveKey('business_type_mismatch')
        ->and($report['total'])->toBe(array_sum($report['scanned']));
});
