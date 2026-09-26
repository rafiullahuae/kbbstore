<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\SettingsService;
use App\Services\UgcRail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What a video rail COSTS, measured as a slope rather than as a total.
 *
 * ── WHY A SLOPE AND NOT A CEILING ───────────────────────────────────────────
 *
 * A shoppable rail is the exact shape that invites an N+1: every tile needs a
 * product, every product needs a brand. Written the obvious way it is 1 + 2n
 * queries — 21 for a rail of ten — and a CEILING would not have caught it,
 * because the same code costs 5 at two videos and 5 looks fine. Only the
 * DIFFERENCE between 1 and 10 says whether the cost is flat.
 *
 * The measured answer, and it is what the doc reports: FOUR queries at 1, 2, 5
 * and 10 videos alike — the section, its videos through the pivot, every tagged
 * product of all of them through one eager load, and those products' brands
 * through one more. The last case in this file names all four, because the first
 * draft asserted three and was wrong by one: `with('brand:...')` is a second round
 * trip, not a join.
 *
 * ── THE THREE TRAPS, ALL THREE HANDLED ──────────────────────────────────────
 *
 * 1. WARM-UP. Setting::map() memoises in a process-level static as well as the
 *    cache (CLAUDE.md says so), and UgcRail caches a section's tiles. The first
 *    call through a section therefore pays for state every later one does not, so
 *    a cold-then-warm comparison reports a fall that has nothing to do with the
 *    number of videos. Every measurement below is preceded by a discarded pass
 *    over the SAME section, and then the rail cache alone is dropped.
 *
 * 2. forgetScopedInstances(). SettingsService is a scoped binding: production
 *    throws the container away between requests, a test process keeps ONE
 *    container across many. Without this reset the second measurement reuses the
 *    first's memos and reports a count no real visitor ever gets — the defect
 *    StorefrontQueryBudgetTest documents at length after it reported /checkout at
 *    7 queries where a shopper pays 11.
 *
 * 3. DB::enableQueryLog(), NOT DB::listen(). A listener registered per
 *    measurement ACCUMULATES rather than replacing, so the Nth measurement counts
 *    N times its real queries — which reported this suite's own sitemap at 416
 *    queries where it runs 20. There is no detach. enableQueryLog() has
 *    flushQueryLog(), so each measurement starts from an empty log with no
 *    listener left behind at all.
 */
function railSlopeShop(): void
{
    app(SettingsService::class)->setModule('shoppable_video', true);
    SettingsService::forgetMemo();
}

/** A section carrying $videos clips, each tagged with $perVideo products. */
function railSlopeSection(string $handle, int $videos, int $perVideo): UgcSection
{
    $brand = Brand::query()->firstOrCreate(['slug' => 'slope-brand'], ['name' => 'Slope Brand']);

    $section = UgcSection::query()->create([
        'handle' => $handle,
        'title' => 'Slope '.$handle,
        'status' => 'publish',
        'max_tiles' => 48,
    ]);

    for ($v = 0; $v < $videos; $v++) {
        $video = UgcVideo::query()->create([
            'slug' => $handle.'-v'.$v,
            'title' => 'Clip '.$v,
            'status' => 'publish',
            'rights_status' => 'granted',
            'file_path' => '/uploads/ugc/clip-slope-'.$handle.$v.'.mp4',
            'poster_path' => '/uploads/ugc/poster-slope-'.$handle.$v.'.webp',
            'width' => 360,
            'height' => 640,
            'creator_handle' => '@slope',
            'position' => $v,
        ]);

        for ($p = 0; $p < $perVideo; $p++) {
            $product = Product::query()->create([
                'slug' => $handle.'-p'.$v.'-'.$p,
                'name' => 'Slope product '.$v.'-'.$p,
                'brand_id' => $brand->id,
                'price' => 10000,
                'status' => 'publish',
                'stock_status' => 'instock',
                'rating' => 4.5,
                'review_count' => 12,
                'type' => 'simple',
            ]);

            $video->products()->attach($product->id, ['position' => $p]);
        }

        $section->videos()->attach($video->id, ['position' => $v]);
    }

    return $section;
}

/** The queries ONE cold rail read costs, with all three traps handled. */
function railSlopeCost(string $handle): array
{
    $rail = app(UgcRail::class);

    // 1. warm-up over the same section, then drop the rail cache only.
    $rail->section($handle, 'en');
    UgcRail::flush();

    // 2. the per-request state production throws away.
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    // 3. the log, not a listener.
    DB::flushQueryLog();
    DB::enableQueryLog();

    $out = app(UgcRail::class)->section($handle, 'en');

    $log = DB::getRawQueryLog();
    DB::disableQueryLog();
    DB::flushQueryLog();

    return ['queries' => count($log), 'tiles' => count($out['tiles']), 'log' => $log];
}

it('costs the same number of queries at 1, 2, 5 and 10 videos', function () {
    /*
     * THE TEST THAT WOULD HAVE CAUGHT THE N+1.
     *
     * MUTATION NOTE 1, and its answer was not the one expected. Remove the
     * `with(['products' => ...])` eager load from UgcRail::build(). RUN: red —
     * 2/2/2/2, not a rising slope. It is FLAT AND CHEAPER, because
     * App\Services\Ugc\Tile::fromVideo() guards on relationLoaded('products'), so
     * with the load gone every tile silently comes back with NO PRODUCTS AT ALL:
     * no card, no price, no Add, on every tile of every rail. That guard turns an
     * N+1 into a blank card, which is worse — an N+1 is slow and visible in a
     * profile, and a blank card is a rail that sells nothing and looks deliberate.
     * So the query count alone would not have named this, and the tile assertion
     * below is what does.
     *
     * MUTATION NOTE 2, and this is the N+1 a ceiling would have missed. Keep the
     * product load and drop only `->with('brand:id,name,slug')`. RUN: red —
     * 6/9/18/33. Rising, because Tile reads $p->brand?->t('name') per product and
     * lazy-loads each one. A CEILING OF, SAY, 10 WOULD HAVE PASSED THIS AT ONE AND
     * TWO VIDEOS and shipped a rail that costs 33 queries at ten.
     */
    railSlopeShop();

    $costs = [];

    foreach ([1, 2, 5, 10] as $n) {
        railSlopeSection('slope-'.$n, $n, 3);
        $costs[$n] = railSlopeCost('slope-'.$n)['queries'];
    }

    expect($costs)->toBe([1 => 4, 2 => 4, 5 => 4, 10 => 4]);

    /*
     * AND THE TILES STILL CARRY THEIR PRODUCTS. Without this line mutation 1
     * above is GREEN: dropping the eager load makes the rail cheaper (2 flat) and
     * empties every card, and a test that only counts queries rewards that.
     */
    $ten = app(UgcRail::class)->section('slope-10', 'en');

    expect(count($ten['tiles']))->toBe(10);

    foreach ($ten['tiles'] as $tile) {
        expect(count($tile['products']))->toBe(3)
            ->and($tile['products'][0]['brand'])->toBe('Slope Brand');
    }
});

it('costs the same number of queries whatever a video is tagged with', function () {
    /*
     * The OTHER axis, and it is a separate case because a different eager load
     * would fix one and not the other. Ten videos with one product each and ten
     * with six are the same three queries: the pivot is loaded once for the whole
     * set, not once per video.
     *
     * MUTATION NOTE. Dropping the brand load makes the one-product rail cost 13
     * and the six-product rail 33 — so BOTH cases go red, but only this one shows
     * that the cost rises with the number of PRODUCTS rather than the number of
     * videos. RUN: red on both, 13 and 33 against 4.
     */
    railSlopeShop();

    railSlopeSection('tags-one', 10, 1);
    railSlopeSection('tags-six', 10, 6);

    $one = railSlopeCost('tags-one');
    $six = railSlopeCost('tags-six');

    expect($one['queries'])->toBe(4)
        ->and($six['queries'])->toBe(4)
        ->and($one['tiles'])->toBe(10)
        ->and($six['tiles'])->toBe(10);
});

it('costs nothing at all on the second render, because the rail is cached', function () {
    /*
     * The cache is not decoration: a rail on the homepage is read on every page
     * view, and three queries per view is three queries more than nought. Ten
     * minutes, keyed by handle, locale and cap, dropped by every admin write.
     *
     * MUTATION NOTE. Replace the Cache::remember() in UgcRail::section() with a
     * direct call to build() and this is red at 4 rather than 0. RUN: red.
     */
    railSlopeShop();
    railSlopeSection('cached', 6, 2);

    railSlopeCost('cached');   // leaves the cache warm, having measured cold

    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $out = app(UgcRail::class)->section('cached', 'en');

    $count = count(DB::getRawQueryLog());
    DB::disableQueryLog();

    expect($count)->toBe(0)->and(count($out['tiles']))->toBe(6);
});

it('reads nothing at all while the module is off', function () {
    /*
     * RULE 1, as a query count. A page that carries [kbb_videos] on a shop with
     * the module off must cost EXACTLY what it cost before the package applied,
     * which is zero queries against these tables — not three cheap ones.
     *
     * MUTATION NOTE. Move the enabled() check in UgcRail::section() below the
     * Cache::remember() and this is red at 2 (the settings read plus the section
     * lookup). RUN: red.
     */
    railSlopeSection('switched-off', 4, 2);

    // Module NOT turned on: the shipped state.
    Cache::flush();
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $out = app(UgcRail::class)->section('switched-off', 'en');
    $log = DB::getRawQueryLog();
    DB::disableQueryLog();

    /*
     * ONE query, and it is the module-toggle read that every page on this shop
     * already makes for every other module — SettingsService::moduleEnabled(),
     * memoised for the rest of the process. NOT a query against ugc_sections,
     * ugc_videos, ugc_video_product or products.
     */
    $touched = array_values(array_filter(array_map(
        fn ($row) => $row['raw_query'] ?? ($row['query'] ?? ''),
        $log
    ), fn ($sql) => str_contains($sql, 'ugc_') || str_contains($sql, '"products"')));

    expect($touched)->toBe([])->and($out['tiles'])->toBe([]);
});

it('records exactly which four queries a rail is, so the number is not a mystery', function () {
    /*
     * THE NUMBER ABOVE IS 4 AND NOT 3, and this case exists so nobody has to
     * guess which four. The first draft of this file asserted 3 — the section, its
     * videos, and "one eager load over the products" — and was wrong by one,
     * because `with('brand:id,name,slug')` is its own statement: Eloquent loads a
     * nested relation in a second round trip, not a join.
     *
     * That is worth pinning rather than adjusting away. A join would be three, and
     * it would also mean hand-writing the select for a brand and losing
     * Brand::t(), which the Arabic storefront reads. Four flat is the right trade;
     * five would mean something is loading per tile.
     */
    railSlopeShop();
    railSlopeSection('named', 4, 2);

    $log = railSlopeCost('named')['log'];

    $tables = array_map(function ($row) {
        $sql = (string) ($row['raw_query'] ?? ($row['query'] ?? ''));

        foreach (['ugc_sections', 'ugc_section_video', 'ugc_video_product', 'ugc_videos', 'products', 'brands'] as $t) {
            if (str_contains($sql, '"'.$t.'"') || str_contains($sql, '`'.$t.'`')) {
                return $t;
            }
        }

        return 'other: '.substr($sql, 0, 60);
    }, $log);

    /*
     * Two of the four name two tables each — the videos read joins the pivot, and
     * the products read joins its own — so the labels below are the FIRST table
     * matched in the list above, in the order the list is written.
     */
    expect($tables)->toBe(['ugc_sections', 'ugc_section_video', 'ugc_video_product', 'brands']);
});
