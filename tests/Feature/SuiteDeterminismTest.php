<?php

declare(strict_types=1);

use App\Models\Product;
use Tests\Support\DeterministicRandom;

/**
 * The suite has to say the same thing twice.
 *
 * THE INCIDENT. Four consecutive full runs on an untouched tree reported
 * 17092, 17087, 17086 and 17086 assertions — all green, all different. The
 * whole of that difference was one test,
 * StorefrontImagesAndHeadingsTest::"it gives every rendered image a non-empty
 * alt", which asserts once per rendered <img> across eight storefront pages.
 * The number of images was different because the CATALOGUE was different.
 *
 * The catalogue is drawn once per process by a migration —
 * 2026_08_27_100000_seed_demo_catalogue runs DemoCatalogueSeeder, which sets
 * `price` from mt_rand(35, 220) * 100 and `total_sales` from mt_rand(0, 900).
 * RefreshDatabase runs that migration set inside the FIRST test of the run, and
 * every later `test()->seed(DatabaseSeeder::class)` is a no-op over it because
 * the seeder is firstOrCreate throughout. So those 24 rows are fixture state
 * that outlives every test in the run, and before Tests\Support\DeterministicRandom
 * they were redrawn on every run:
 *
 *     /everything-under-54-aed selects COALESCE(NULLIF(sale_price,0), price)
 *     <= 5400 and rendered 1, 2, 3, 5 and 7 products on five consecutive runs.
 *
 * THIS FILE IS THE GUARD. Not "the fixture is random-ish and that is fine" but
 * the exact rows, so that the next change which makes the fixture depend on
 * something outside the seed fails here, once, with a message that says what
 * happened — rather than somewhere else, sometimes, as a number nobody can
 * reproduce.
 *
 * WHEN THIS FAILS AND YOU BELIEVE THE CHANGE. If you deliberately changed
 * DemoCatalogueSeeder or the migration set, re-pin the constants below from a
 * run and say so in the commit. If you did NOT change either, do not re-pin:
 * something has reintroduced unpinned randomness into the fixture and the
 * suite is back to disagreeing with itself.
 */

/*
 * The catalogue this suite runs against, under DeterministicRandom::DEFAULT_SEED.
 *
 * Hashes rather than 24 literals for the two random columns, so the failure
 * message stays readable; the counts that other tests silently depend on are
 * spelled out, because those are the numbers that actually move.
 */
const DET_PRICE_HASH = '93b6ba5a766362e705a06494a191360c';
const DET_SALES_HASH = 'a7ad0ddc4b6e4bedcebbfc104f50e1fe';

it('draws the same demo catalogue on every run', function () {
    if (DeterministicRandom::seed() !== DeterministicRandom::DEFAULT_SEED) {
        /*
         * A seed sweep (KBB_TEST_RANDOM_SEED=7 vendor/bin/pest) is a supported
         * and useful thing to do — it re-rolls the catalogue on purpose to find
         * tests secretly asserting against one draw. It is not this test's
         * claim, so this one steps aside rather than reporting the sweep as a
         * regression.
         */
        test()->markTestSkipped('KBB_TEST_RANDOM_SEED is set, so the catalogue is deliberately a different draw.');
    }

    $rows = Product::query()->whereNull('wc_id')->orderBy('id')
        ->get(['sku', 'price', 'sale_price', 'total_sales']);

    expect($rows)->toHaveCount(24, 'the demo catalogue is not the 24 rows the storefront tests render');

    $prices = implode(',', $rows->pluck('price')->all());
    $sales = implode(',', $rows->pluck('total_sales')->all());

    $why = "\n\nThe demo catalogue is drawn by a migration with mt_rand(), once per run, and "
        ."Tests\\Support\\DeterministicRandom pins the stream so that draw is the same every time. "
        ."A different draw here means either DemoCatalogueSeeder changed (re-pin, and say so) or "
        ."something is drawing outside the pinned stream (find it — the suite is nondeterministic again).";

    expect(md5($prices))->toBe(DET_PRICE_HASH, 'products.price is not the pinned draw: '.$prices.$why);
    expect(md5($sales))->toBe(DET_SALES_HASH, 'products.total_sales is not the pinned draw: '.$sales.$why);
});

it('renders the same number of products on the price-filtered collection every run', function () {
    if (DeterministicRandom::seed() !== DeterministicRandom::DEFAULT_SEED) {
        test()->markTestSkipped('KBB_TEST_RANDOM_SEED is set, so the catalogue is deliberately a different draw.');
    }

    /*
     * The derived number that actually moved. /everything-under-54-aed is the
     * one storefront page whose ROW COUNT is a function of the random draw, and
     * an assertion made once per rendered row is what turned that into a
     * drifting assertion total. Pinned here so the drift is caught as itself
     * rather than as a number in a summary line.
     */
    expect(Product::query()->visible()
        ->whereRaw('COALESCE(NULLIF(sale_price, 0), price) <= ?', [5400])
        ->count())
        ->toBe(2, 'the under-AED-54 collection is not the pinned size, so any test asserting per rendered product will drift');
});

it('builds no fixture out of randomness the suite cannot pin', function () {
    /*
     * THE WIDER CLASS. mt_srand() pins mt_rand(), shuffle(), array_rand() and
     * str_shuffle(). It cannot reach random_int(), random_bytes(),
     * Str::random() or Str::uuid(), which are CSPRNG by design and MUST stay
     * that way — tokens and uuids have to be unique or tests collide on unique
     * indexes.
     *
     * So the rule is about WHERE, not about which function: a fixture that the
     * whole suite reads — a seeder, or a migration that seeds — may not be
     * built out of something unpinnable, because the next person to add
     * `'price' => random_int(...)` to DemoCatalogueSeeder would reintroduce
     * exactly the drift this lane removed and nothing would say so.
     *
     * database/factories is excluded: a factory is called BY a test, for that
     * test's own rows, and Str::random() in a remember_token there is the
     * correct thing.
     */
    $unpinnable = '/\b(random_int|random_bytes)\s*\(|\bStr::(random|uuid|ulid)\s*\(/';

    $offenders = [];

    foreach ([database_path('seeders'), database_path('migrations')] as $dir) {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            // Comments discuss these functions; only code counts.
            $source = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

            if (preg_match($unpinnable, $source) === 1) {
                $offenders[] = basename($file);
            }
        }
    }

    expect($offenders)->toBe([],
        'these seed the shared fixture with randomness that KBB_TEST_RANDOM_SEED cannot pin, '
        .'so the suite will draw a different catalogue on every run: '.implode(', ', $offenders)
        .'. Use mt_rand() (pinned by Tests\\Support\\DeterministicRandom) or a literal.');
});
