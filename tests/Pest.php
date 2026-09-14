<?php

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;

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
    })
    ->in('Feature');
