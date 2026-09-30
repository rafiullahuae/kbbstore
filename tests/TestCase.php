<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\CompiledCaches;
use Tests\Support\DeterministicRandom;
use Tests\Support\NeedleScan;
use Tests\Support\RecordingTestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test's application is built here, and it is built from the
     * environment rather than from a compiled config cache.
     *
     * LoadConfiguration consults Application::configurationIsCached() first and,
     * if a compiled file is there, `require`s it and never calls env() at all.
     * One such file is therefore a complete override of the suite's database
     * settings — past phpunit-mysql.xml's force="true" and past
     * tests/bootstrap.php both. With a config cache naming sqlite in place,
     * `vendor/bin/pest -c phpunit-mysql.xml tests/Feature/OrderNumbersTest.php`
     * fails all eleven tests with "no such column: deleted_at (Connection:
     * sqlite)": a MySQL run reading a half-migrated SQLite file.
     *
     * Such a file is written by every `php artisan migrate` against this
     * checkout, because warm_caches_2_60_4 calls config:cache and route:cache
     * from inside the migration set and the clear_caches_* migrations only
     * remove them again at the end. Another lane's terminal, the browser
     * previews' `migrate --force` subprocess and an UpdateRunner apply all open
     * that window. Discarding here is what keeps a test out of it.
     *
     * Tests\Support\CompiledCaches carries the reproduction, and the reason this
     * is a delete rather than a redirection of APP_CONFIG_CACHE.
     *
     * Cheap: three is_file() calls and one glob per test.
     */
    /**
     * The ONE seam in front of assertSee, and it is inert unless a scan is on.
     *
     * ── WHY IT IS HERE AND NOT IN THE INSTRUMENT ────────────────────────────
     *
     * tools/plc-needle-scan.php measures how many times each assertion's needle
     * occurs in the haystack it ran against — the defect being an assertion
     * that is green for a reason unrelated to the code under test. Pest's
     * expectations are pipeable, so `toContain` and `toMatch` are hooked
     * without the suite knowing. `assertSee` is not: it lives on
     * Illuminate\Testing\TestResponse and hands its haystack straight to
     * PHPUnit through TestResponseAssert, an `@internal` class with a private
     * constructor and no injectable factory.
     *
     * MakesHttpRequests::createTestResponse() is the documented override point
     * for exactly this, so it is used, and the cost is deliberately small
     * enough to be worth reading:
     *
     *   · NOTHING IS COPIED FROM THE FRAMEWORK. The parent builds the response
     *     it always built — including the LoggedExceptionCollection wiring that
     *     `assertThrows` and friends need — and the recording response is then
     *     built from `$baseResponse` and handed `$exceptions`, both public API.
     *     A reimplementation of the parent's body would have been framework
     *     internals living in this class, rotting silently on the next upgrade.
     *
     *   · WHEN NOBODY IS SCANNING, THIS IS ONE NULL CHECK and the parent's own
     *     object is returned untouched. RecordingTestResponse is not even
     *     autoloaded.
     *
     *   · AND IT CHANGES NO ASSERTION. The subclass records and then calls
     *     parent::assertSee(), so the assertion that runs is Laravel's, with
     *     its escaping and its exception decoration intact.
     *
     * @param  \Illuminate\Http\Response  $response
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Testing\TestResponse
     */
    protected function createTestResponse($response, $request)
    {
        $made = parent::createTestResponse($response, $request);

        if (! NeedleScan::armed()) {
            return $made;
        }

        return RecordingTestResponse::fromBaseResponse($made->baseResponse, $request)
            ->withExceptions($made->exceptions);
    }

    public function createApplication(): Application
    {
        CompiledCaches::discard();

        /*
         * The Mersenne Twister, returned to the run's seed before every
         * application is built.
         *
         * Here rather than in tests/Pest.php's beforeEach because of WHEN the
         * demo catalogue is drawn. It is seeded by a migration
         * (2026_08_27_100000_seed_demo_catalogue), so RefreshDatabase draws it
         * inside the FIRST test of the process -- from setUpTraits(), which
         * Laravel runs after createApplication() and before any beforeEach. A
         * reseed in beforeEach is therefore a reseed after the fixture already
         * exists, and was measured to leave the catalogue as random as it was.
         *
         * Tests\Support\DeterministicRandom carries what that cost: a
         * storefront fixture that differed on every run, and an assertion count
         * that differed with it.
         */
        DeterministicRandom::reseed();

        /*
         * The execution-time limit, put back to "no limit" before every test.
         *
         * Not defensive tidying — this suite dies without it, and the way it
         * dies is the worst kind.
         *
         * Admin\ImportApiController::step() calls `@set_time_limit(110)`. That
         * is right in production: the host is shared, its max_execution_time is
         * about 30 seconds, and one import slice needs longer. It is a RAISE
         * there.
         *
         * Under the CLI the default limit is 0, meaning no limit, so the same
         * call is a LOWER: the first test that exercises the import step arms a
         * 110-second countdown over the whole PHP process, and the suite is
         * then killed 110 wall-clock seconds later wherever it happens to be.
         * The crash is a bare "Maximum execution time of 110 seconds exceeded"
         * pointing at DateFactory, or Router, or whatever innocent frame the
         * timer expired in — never at the import test that armed it, and in a
         * different place on every run.
         *
         * It was live and invisible before this line existed because the suite
         * finished at about 152 seconds against a budget that ran out at
         * roughly 155. Any lane adding a few seconds of tests inherited a red
         * suite it did not break, with a stack trace pointing at somebody
         * else's code.
         *
         * The real fix belongs in ImportApiController, which should only ever
         * raise the limit:
         *
         *     $current = (int) ini_get('max_execution_time');
         *     if ($current !== 0 && $current < self::STEP_SECONDS) {
         *         @set_time_limit(self::STEP_SECONDS);
         *     }
         *
         * That file is another lane's, so this is the harness protecting itself
         * rather than a fix to the cause, and it stays useful afterwards: any
         * future `set_time_limit` anywhere in the application is contained to
         * the single test that provokes it.
         */
        @set_time_limit(0);

        return parent::createApplication();
    }
}
