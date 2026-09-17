<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The suite's pseudo-random stream, pinned so that two runs agree.
 *
 * WHAT WENT WRONG. The demo catalogue is not seeded by a seeder a test calls —
 * it is seeded by a MIGRATION, 2026_08_27_100000_seed_demo_catalogue, which
 * runs `(new DemoCatalogueSeeder)->run()`. Under RefreshDatabase that migration
 * set runs ONCE per process, inside the first test, and every later
 * `test()->seed(DatabaseSeeder::class)` is a no-op against it because the
 * seeder is written with firstOrCreate. So the 24 demo products the whole
 * storefront half of the suite renders are drawn exactly once, at the top of
 * the run, and their `price` and `total_sales` come from an UNSEEDED
 * `mt_rand()`:
 *
 *     $price = (int) ((mt_rand(35, 220)) * 100);          // 3500 .. 22000
 *     'total_sales' => mt_rand(0, 900),
 *
 * That is fixture state that outlives every test in the run and differs
 * between runs, which is the definition of a suite that cannot say the same
 * thing twice. It was measured as a drifting assertion COUNT, because the
 * number of assertions some tests make is the number of rows the catalogue
 * happens to contain:
 *
 *     /everything-under-54-aed selects
 *         COALESCE(NULLIF(sale_price, 0), price) <= 5400
 *
 *     so it renders however many of the 24 random prices fell under AED 54 —
 *     measured at 1, 2, 3, 5 and 7 products on five consecutive runs — and
 *     StorefrontImagesAndHeadingsTest asserts once per rendered <img>. Four
 *     consecutive full runs on an untouched tree reported 17092, 17087, 17086
 *     and 17086 assertions, and the whole of that difference was one test.
 *
 * The same draw decides `total_sales`, which is the ONLY sort key
 * /best-sellers has (`orderByDesc('total_sales')->orderByDesc('review_count')`
 * over a catalogue whose review_count is 0 for every row), so two demo
 * products drawing the same total_sales — a birthday collision with a ~26%
 * chance per run over 24 draws from 901 values — leaves that page's order
 * decided by whatever the engine feels like. Pinning the draw pins that too.
 *
 * WHY THE RESET LIVES WHERE IT DOES. mt_srand() in tests/Pest.php's beforeEach
 * is too late and was measured to be: Pest's beforeEach runs after
 * Illuminate\Foundation\Testing\TestCase::setUp() has already called
 * setUpTraits(), which is where RefreshDatabase runs migrate:fresh. Seeding
 * there leaves the RNG state at the start of every test identical (verified:
 * the same first draw on every run) and the catalogue still different on every
 * run, because the catalogue was drawn before the beforeEach ever ran.
 *
 * createApplication() is the seam that IS early enough — Laravel calls it from
 * setUp() before setUpTraits() — so Tests\TestCase calls this there, and
 * tests/bootstrap.php calls it once more at process start for anything that
 * draws before the first application exists.
 *
 * WHAT THIS DOES NOT PIN, on purpose. random_int(), random_bytes() and
 * Str::random() are CSPRNG and are not reachable from mt_srand(). That is the
 * right way round: tokens, uuids and `Str::random(6)` fixture suffixes have to
 * stay unique or tests collide on unique indexes. The cost is that a FIXTURE
 * built out of random_int() cannot be pinned by this at all —
 * SuiteDeterminismTest guards against one being introduced.
 */
final class DeterministicRandom
{
    /**
     * The seed. A date, so it is obviously arbitrary and obviously not
     * meaningful — any constant would do.
     *
     * Overridable with KBB_TEST_RANDOM_SEED, which is what makes this useful
     * rather than merely quiet: sweeping the seed over a few values re-rolls
     * the whole demo catalogue and is the cheapest way to find a test that is
     * secretly asserting against one particular draw.
     *
     *     KBB_TEST_RANDOM_SEED=7 vendor/bin/pest
     */
    public const DEFAULT_SEED = 20260917;

    public static function seed(): int
    {
        $requested = getenv('KBB_TEST_RANDOM_SEED');
        $requested = is_string($requested) ? trim($requested) : '';

        return $requested === '' || ! ctype_digit($requested)
            ? self::DEFAULT_SEED
            : (int) $requested;
    }

    /**
     * Return the Mersenne Twister to the run's seed.
     *
     * Called before every application is built rather than once per process:
     * once per process would pin the run as a whole but still let the state a
     * given test sees depend on how much randomness the tests before it drew,
     * which is the same order dependence in a quieter form.
     *
     * MT_RAND_MT19937 is named rather than left to the default so that a
     * future php.ini or PHP release cannot change which stream a seed selects.
     */
    public static function reseed(): void
    {
        mt_srand(self::seed(), MT_RAND_MT19937);
    }
}
