<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Support\DemoSeed;
use Illuminate\Support\Facades\DB;

/**
 * Lane EC — the facts the admin's "demo rows excluded" sentence has to be
 * written from, pinned so the sentence can only be written one way.
 *
 * ── WHERE THIS CAME FROM ────────────────────────────────────────────────────
 *
 * Lane DT added demo REVIEWS to App\Support\DemoSeed::counts() and carried them
 * on all four reporting endpoints, and deliberately kept them OUT of
 * disclosure()['excluded'] — because the dashboard banner reads
 *
 *     (s.demo && s.demo.excluded) ? ' <b>'+s.demo.orders+'</b> demo orders are
 *     excluded from these figures.' : ''
 *
 * and a fresh install has demo reviews and no demo orders, so folding them in
 * would have printed "0 demo orders are excluded from these figures."
 *
 * THAT REASONING IS CORRECT AND IT DOES NOT GO FAR ENOUGH, which is what this
 * file is for. `excluded` is
 *
 *     $counts['orders'] + $counts['customers'] + $counts['products'] > 0
 *
 * so the SAME false sentence is reachable today, without reviews being involved
 * at all: Store → Demo Content imports one type at a time
 * (DemoContentController::import), and an owner who imports demo PRODUCTS and
 * no demo orders flips `excluded` true with `orders` still 0. Measured, not
 * reasoned about — that is the test below, and it is why the banner must be
 * worded from THE INDIVIDUAL COUNTS and never from `excluded`.
 *
 * `excluded` is still the right flag for what it says: something was left out
 * of the money figures on this screen. It is not, and never was, a licence to
 * print the word "orders".
 *
 * ── WHAT THIS FILE DOES NOT DO ──────────────────────────────────────────────
 *
 * It does not change `excluded`. Four endpoints and two screens already read
 * it with its current meaning, and a field that changes meaning under readers
 * who were not updated is how a disclosure becomes wrong quietly. The fix
 * belongs in the sentence.
 */

/** An owner account, named so it cannot collide with another file's helper. */
function dsentAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Disclosure sentence probe',
        'email' => 'dsent-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** The disclosure block one endpoint hands its screen. */
function dsentDisclosure(AdminUser $admin, string $endpoint = '/admin-api/stats'): array
{
    return test()->actingAs($admin, 'admin')->getJson($endpoint)->assertOk()->json('demo');
}

/*
|------------------------------------------------------------------------------
| 1. "excluded" is true while "orders" is zero — the sentence must survive it
|------------------------------------------------------------------------------
*/

it('flips "excluded" on for demo products while demo orders stay at zero', function () {
    $admin = dsentAdmin();

    // One type, which is exactly what the Demo Content screen offers: a button
    // per kind, not an all-or-nothing switch.
    $this->actingAs($admin, 'admin')->postJson('/admin-api/demo-content/products/import')->assertOk();

    $demo = dsentDisclosure($admin);

    expect($demo['products'])->toBeGreaterThan(0, 'importing demo products seeded none; this test is measuring nothing');

    expect($demo['excluded'])->toBeTrue(
        'demo products no longer flip the sales-figure disclosure on, so the dashboard would say nothing '
        . 'about product counts that really are being reduced.',
    );

    /*
     * THE PAIR THAT MATTERS. Both true at once, which is what makes
     *
     *     excluded ? demo.orders + ' demo orders are excluded…' : ''
     *
     * print "0 demo orders are excluded from these figures." If this assertion
     * ever fails because `orders` became non-zero, somebody has made the
     * products importer seed orders too and the banner is fine by accident; if
     * it fails because `excluded` became false, the disclosure has gone silent
     * about a real exclusion. Either way the sentence needs re-reading.
     */
    expect($demo['excluded'] === true && $demo['orders'] === 0)->toBeTrue(
        'The dashboard banner must be worded from the individual counts, never from `excluded`: '
        . 'this shop has excluded=true and orders=0, so a sentence keyed on `excluded` announces '
        . '"0 demo orders are excluded from these figures".',
    );
});

/*
|------------------------------------------------------------------------------
| 2. Each kind is counted on its own, so a sentence can name them separately
|------------------------------------------------------------------------------
*/

it('counts each kind of demo row separately on every endpoint that discloses them', function () {
    $admin = dsentAdmin();

    foreach (['customers', 'products', 'orders', 'reviews'] as $type) {
        $this->actingAs($admin, 'admin')->postJson('/admin-api/demo-content/' . $type . '/import')->assertOk();
    }

    $expected = DemoSeed::counts();

    foreach (['orders', 'customers', 'products', 'reviews'] as $kind) {
        expect($expected[$kind])->toBeGreaterThan(
            0,
            'the fixture seeded no demo ' . $kind . ', so the per-kind assertion below proves nothing',
        );
    }

    foreach (['/admin-api/stats', '/admin-api/orders', '/admin-api/analytics', '/admin-api/customers'] as $endpoint) {
        $demo = dsentDisclosure($admin, $endpoint);

        foreach (['orders', 'customers', 'products', 'reviews'] as $kind) {
            // array_key_exists rather than ->toHaveKey($k, $msg): Pest reads
            // that second argument as the EXPECTED VALUE, not a message.
            expect(array_key_exists($kind, $demo))
                ->toBeTrue($endpoint . ' stopped disclosing demo ' . $kind);

            expect($demo[$kind])->toBe(
                $expected[$kind],
                $endpoint . ' disagrees with DemoSeed::counts() about demo ' . $kind,
            );
        }
    }
});

/*
|------------------------------------------------------------------------------
| 3. The sentence's claim is true: the named figures really do drop
|------------------------------------------------------------------------------
*/

it('really does leave each kind of demo row out of the dashboard figure that names it', function () {
    // "N demo products are excluded from these figures" is only worth printing
    // if the products figure beside it has actually had them taken off. Checked
    // per kind rather than for orders alone, because the sentence names three.
    $admin = dsentAdmin();

    $before = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk()->json();

    foreach (['customers', 'products', 'orders'] as $type) {
        $this->actingAs($admin, 'admin')->postJson('/admin-api/demo-content/' . $type . '/import')->assertOk();
    }

    $after = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk()->json();

    foreach (['orders', 'customers', 'products'] as $kind) {
        expect($after['demo'][$kind])->toBeGreaterThan(0, 'no demo ' . $kind . ' were seeded');

        expect($after[$kind])->toBe(
            $before[$kind],
            'The dashboard ' . $kind . ' figure moved when demo ' . $kind . ' were imported, so the banner\'s '
            . '"excluded from these figures" is not true of it.',
        );
    }
});

/*
|------------------------------------------------------------------------------
| 4. Reviews are a different claim, and the endpoint keeps them separable
|------------------------------------------------------------------------------
*/

it('discloses demo reviews without claiming they were taken out of these figures', function () {
    /*
     * The reviews sentence says something different on purpose. No figure on
     * the dashboard, the Orders screen, Analytics or Customers counts reviews
     * at all — demo reviews are kept off the STOREFRONT, by
     * App\Support\DemoReviews. So the disclosure must be able to say "hidden
     * from the storefront" for reviews and "excluded from these figures" for
     * the other three, which means the two must never be merged into one
     * boolean.
     */
    $admin = dsentAdmin();

    $this->actingAs($admin, 'admin')->postJson('/admin-api/demo-content/reviews/import')->assertOk();

    $demo = dsentDisclosure($admin);

    expect($demo['reviews'])->toBeGreaterThan(0, 'importing demo reviews seeded none');

    expect($demo['orders'])->toBe(0, 'the reviews importer seeded orders too; this fixture no longer isolates reviews');

    // And the storefront really is where they are withheld: none of them is
    // visible to a shopper reading the review wall.
    $demoIds = DB::table('demo_seed_log')->where('type', 'reviews')->pluck('record_id')->all();

    expect(count($demoIds))->toBeGreaterThan(0, 'the reviews importer logged nothing');

    $visible = \App\Support\DemoReviews::exclude(\App\Models\Review::query())
        ->whereIn('id', $demoIds)
        ->count();

    expect($visible)->toBe(0, 'a demo review survived the storefront exclusion, so "hidden from the storefront" is false');
});
