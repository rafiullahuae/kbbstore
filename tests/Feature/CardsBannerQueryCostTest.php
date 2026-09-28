<?php

declare(strict_types=1);

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * WHAT THE HOMEPAGE PAYS FOR THIS SECTION — Lane BN.
 *
 * The brief: "The homepage must cost the SAME number of queries with the
 * section off, and at most one more with it on — one query for the chosen set
 * and its cards, eager-loaded. Not one per card. Measure it; do not assert it."
 *
 * ── AND MEASURED FLAT, NOT ONLY BOUNDED ─────────────────────────────────────
 *
 * StorefrontQueryBudgetTest's own header says why a budget alone is not enough:
 * "a page doing one query per product passes any budget you like on a small
 * enough fixture, which is exactly how /shop reached 390 queries for four
 * products without any test noticing." So the on-state is measured at three
 * cards AND at twenty-four, and the two counts must be the SAME NUMBER. A
 * `with('cards')` that fell back to lazy loading would pass a ceiling of six
 * and fail this.
 *
 * ── WHY forHome() IS A JOIN AND NOT `BannerSet::with('cards')` ──────────────
 *
 * Because `with()` is TWO queries — the set, then the cards — and the whole
 * allowance for this feature is ONE. Spending it on the shape of the code
 * rather than on the feature is the kind of decision nobody notices until the
 * budget file goes red and somebody raises the ceiling to make it green.
 *
 * ── THE WARM-UP PASS IS COPIED FROM THAT FILE, AND IT IS NOT OPTIONAL ───────
 *
 * Several caches here live for the life of the PROCESS rather than the request
 * — Setting::map() memoises in a function static nothing can reach, and the
 * test cache store is the array driver. Measuring cold-then-warm reports a fall
 * that has nothing to do with the feature. So every measurement below is
 * preceded by one discarded request through the same page.
 */
function bnqSeed(int $cards): BannerSet
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create([
        'name' => 'Budget', 'slug' => 'budget-'.uniqid(), 'status' => 'publish', 'position' => 0,
    ]);

    foreach (range(1, $cards) as $i) {
        BannerCard::create([
            'banner_set_id' => $set->id,
            'image' => 'uploads/banners/bn-'.$i.'.webp',
            'heading' => 'Heading '.$i,
            'body' => 'Body '.$i,
            'button_label' => 'Shop',
            'button_url' => '/shop/',
            'position' => $i,
            'status' => 'publish',
        ]);
    }

    return $set;
}

/** Queries on one GET of the homepage, after a discarded warm-up request. */
function bnqCount(): int
{
    test()->get('/');

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->get('/')->assertOk();

    $n = count(DB::getQueryLog());

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $n;
}

it('costs the homepage nothing at all while the section is off', function () {
    /*
     * MEASURED, and the number is printed in the lane report rather than
     * hard-coded here: what this asserts is the DIFFERENCE, which is the thing
     * that can regress. A ceiling copied out of StorefrontQueryBudgetTest would
     * go stale the first time another lane moved it.
     *
     * THE SET IS CHOSEN AND THE MODULE IS OFF, which is the state that actually
     * measures the gate. Left at "None" this case is green even with the gate
     * deleted, because there would be no set to load either way — measured, and
     * the reason this line is here.
     *
     * MUTATION: delete the `enabled()` short-circuit at the top of
     * Banners::forHome() and this goes red by one, the join a switched-off shop
     * has no use for. Run, red, put back.
     */
    $bare = bnqCount();

    $set = bnqSeed(6);

    app(SettingsService::class)->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    expect(bnqCount())->toBe($bare);
});

it('costs exactly one more query with the section on, however many cards', function () {
    $bare = bnqCount();

    $set = bnqSeed(3);

    $settings = app(SettingsService::class);
    $settings->setModule('cards_banner', true);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    $three = bnqCount();

    /*
     * FLATNESS, which is the property a budget cannot prove. Eight times the
     * cards, the same number of queries.
     *
     * MUTATION: replace the join in Banners::load() with
     * `BannerSet::with('cards')->find($id)` and the +1 below becomes +2; drop
     * the eager load entirely and the 3-card and 24-card counts diverge by 21.
     * Both were run.
     */
    $set = bnqSeed(24);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    $twentyFour = bnqCount();

    expect($twentyFour)->toBe($three)
        ->and($three - $bare)->toBeLessThanOrEqual(1)
        ->and($three)->toBeGreaterThan($bare);
});

it('reads the set and its cards in one query, not two', function () {
    // The same claim at the seam rather than through a whole page, so a failure
    // here says which call grew rather than which page did.
    $set = bnqSeed(12);

    app(SettingsService::class)->setModule('cards_banner', true);
    app(SettingsService::class)->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    $banners = app(Banners::class);
    $banners->forHome();                 // warm the module + settings snapshots

    DB::flushQueryLog();
    DB::enableQueryLog();

    $loaded = $banners->forHome();

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect($loaded)->not->toBeNull()
        ->and($loaded[1])->toHaveCount(12)
        ->and($log)->toHaveCount(1)
        ->and($log[0]['query'])->toContain('inner join');
});

it('never lets a storefront-hydrated set or card be written back', function () {
    /*
     * The models forHome() returns are a VIEW over one query's rows. `exists`
     * is left false on purpose, so a later lane that calls ->save() on one
     * inserts a new row rather than silently rewriting the owner's set from a
     * storefront request. Asserting it because "it is obviously fine" is how
     * this sort of thing ships.
     */
    $set = bnqSeed(2);

    app(SettingsService::class)->setModule('cards_banner', true);
    app(SettingsService::class)->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    [$hydrated, $cards] = app(Banners::class)->forHome();

    expect($hydrated->exists)->toBeFalse()
        ->and($hydrated->isDirty())->toBeFalse()
        ->and($cards[0]->exists)->toBeFalse()
        ->and($cards[0]->isDirty())->toBeFalse();
});
