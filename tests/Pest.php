<?php

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
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
    })
    ->in('Feature');
