<?php

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Tests\Support\ColumnWidths;
use Tests\Support\StaticMemos;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        /*
         * Reclaim the container after the migration set has run.
         *
         * warm_caches_2_60_4 calls Artisan::call('config:cache') and
         * route:cache. Each of those constructs a fresh Application, which
         * calls Container::setInstance() on ITSELF and re-points the facade
         * root and Eloquent's connection resolver at that throwaway instance.
         * Nothing puts them back.
         *
         * Two things then break, both silently. The first test in a process
         * escapes RefreshDatabase's transaction entirely -- transactionLevel()
         * is 0 in test one and 1 in test two -- so whatever it writes survives
         * the whole run. And request() inside a view resolves through the
         * discarded app, so Route::current() is null and getPathInfo() is
         * always '/', which quietly disarms any assertion about the current
         * URL: a canonical test passes against the wrong page.
         *
         * Both lanes that hit this worked around it in their own files. It
         * belongs here, once, for every test.
         */
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Model::setConnectionResolver($this->app['db']);

        /*
         * And the other half of the same problem: state that outlives the
         * container because it never lived in it.
         *
         * RefreshDatabase rolls the database back and the four lines above put
         * the container back, but neither touches a `private static ?array
         * $memo`. Whatever the first test to call Setting::map(), Url::base() or
         * IndexNow::key() resolved is what every later test in the process gets,
         * however that test seeded its own data — so the suite's answer depends
         * on which test ran first, which is exactly what --order-by=random,
         * .phpunit.result.cache and running one file instead of all of them all
         * change.
         *
         * About thirty test files already call SettingsService::forgetMemo() in
         * their own beforeEach. That is this fix, written thirty times by the
         * lanes that got bitten and missing from every file written by a lane
         * that did not. It belongs here, once, for every test and for every
         * memo — Tests\Support\StaticMemos lists them, and
         * StaticMemoIsolationTest fails if a new one is added without being
         * registered there.
         *
         * After the container is reclaimed, never before: several of these
         * clear a cache entry as well, through a facade.
         */
        StaticMemos::forgetAll();

        /*
         * And a fourth: the order-reminder heartbeat (2.60.372).
         *
         * App\Services\Mail\OrderReminderTick runs the reminder sweep at the
         * end of whichever request finds its marker file a minute old -- three
         * queries, on a request chosen by the wall clock. In a fifteen-minute
         * suite that is a random request in a random test, so every test that
         * COUNTS queries could gain three: SetFixedPriceFollowsMembersTest
         * measured a set of three at 13 and a set of twelve at 10, and passed
         * alone. Touching the marker holds the sweep off for this test; the
         * tests that are about the reminders unlink it in their own
         * beforeEach, which runs after this one.
         */
        @touch(\App\Services\Mail\OrderReminderTick::markerPath());

        // (Lane CT) And Cart Tracking's retention heartbeat, for the same
        // reason: a sweep on a random request in a random test would add its
        // queries to whatever that test counts. CartTrackingRetentionTest
        // unlinks it where the heartbeat is the subject.
        @touch(\App\Services\CartTracking\CartTrackingTick::markerPath());


        /*
         * (Lane PF2) And the banner tier. A homepage request that renders a
         * banner wider than 1280 schedules its 1280/1440/1600 copies after the
         * response, into the REAL public/img-cache, and a fixture that is not
         * forget()-ed would leave them there for the next test's srcset to
         * find -- a string pinned in one test changing because another ran
         * first. Held off here; WideBannerSrcsetTest releases it.
         */
        config(['kbb.image_wide_after_response' => false]);

        /*
         * And Marketing Emails' scheduler heartbeat (Lane MK) -- which is NOT a
         * request heartbeat, and that is the point of writing it down here.
         * Campaigns are never sent on the tail of a page view (plan §4.5), so
         * there is nothing for a test's requests to trigger and nothing to
         * hold off. What the marker does carry is "a scheduler tick was seen
         * in the last ten minutes", which the Campaigns screen reads to decide
         * whether to show the cron line; a tick run by one test would make the
         * next test's screen say the scheduler is alive. Removing it gives
         * every test the honest default -- no tick seen -- and a test about
         * the scheduler runs the tick itself.
         */
        @unlink(\App\Services\Marketing\CampaignTick::markerPath());

        /*
         * And the third thing that outlives a test: PHP's own execution clock.
         *
         * App\Http\Controllers\Admin\ImportApiController::step() calls
         * @set_time_limit(110). That is right for the endpoint — a slice of an
         * import must not be the thing that hangs a shared host's worker — and
         * it is PROCESS-WIDE. PHPUnit runs the whole suite in one process, so
         * from the moment any test drives that endpoint, EVERY REMAINING TEST
         * IN THE RUN shares a single 110-second budget, and the run dies with
         * "Maximum execution time of 110 seconds exceeded" at whatever
         * unrelated line it happened to reach when the budget ran out.
         *
         * That is why the failure looked like a defect in
         * Illuminate\Collections\Arr one run and in OrderEmailPresenter the
         * next: the location is wherever the clock stopped, not where the cost
         * is. It is a slow fuse — the suite creeps towards the ceiling as tests
         * are added, and the lane that happens to cross it gets a fatal in
         * somebody else's file.
         *
         * 0 is the CLI default and what every test outside that window already
         * runs under, so this restores the intended behaviour rather than
         * relaxing anything. Nothing here is a timeout defence: `timeout` in CI
         * and in the commands in CLAUDE.md is.
         *
         * Found on the MySQL run, where the suite is slower and crossed first.
         * (Lane EP)
         */
        @set_time_limit(0);

        /*
         * And the fourth: a column width nothing here enforces.
         *
         * SQLite discards the declared length of string(), char() and uuid()
         * before the table exists — the grammar compiles all three to the bare
         * word `varchar`. MySQL keeps it and, under STRICT_TRANS_TABLES,
         * refuses an over-long value with SQLSTATE[22001] 1406. So a write that
         * is fatal on the live shop is invisible to every test in this suite.
         *
         * Five CartFooterTest cases wrote Str::random(40) into `carts.token`,
         * which is uuid() and so char(36). Green here, red on the MySQL config,
         * and nothing in between could have told anyone. Tests\Support\
         * ColumnWidths carries a checked-in fingerprint of the MySQL schema
         * and reports a write that would not fit; ColumnWidthGuardTest keeps
         * that fingerprint honest against a real information_schema.
         *
         * Here rather than in one guard test, because the defect it was
         * written for was in a FIXTURE — no endpoint-shaped guard would ever
         * have been pointed at it. It costs one str_starts_with() on a
         * statement that is not an INSERT or an UPDATE.
         */
        ColumnWidths::watch();
    })
    ->afterEach(function () {
        $overWide = ColumnWidths::violations();
        ColumnWidths::reset();

        expect($overWide)->toBe([], 'a value was written wider than its column');
    })
    ->in('Feature');
