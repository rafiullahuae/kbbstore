<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AdminPathService;
use App\View\Composers\StoreComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * (Lane TY) The Blade compiler writes its compiled views atomically. Its
     * stock write truncates the file and then fills it, and a request that
     * includes it in between renders that partial as NOTHING -- the empty
     * "Your order" of the owner's order #56181. See App\View\AtomicViewFiles.
     *
     * extend(), not a re-registration: the compiler Laravel built stays the
     * same object with the same paths, directives and components; only the
     * Filesystem it writes through is swapped.
     */
    public function register(): void
    {
        $this->app->extend('blade.compiler', function ($blade) {
            \Closure::bind(function () {
                $this->files = new \App\View\AtomicViewFiles;
            }, $blade, \Illuminate\View\Compilers\Compiler::class)();

            return $blade;
        });
    }

    public function boot(): void
    {
        View::composer('layouts.store', StoreComposer::class);



        /*
         * Supplies the admin-address section on the Updates screen.
         *
         * Done with a composer rather than by editing UpdateController, because
         * that controller has been repaired in place on the server several
         * times and shipping my copy of it would silently undo those fixes.
         * A composer adds what the view needs without touching it.
         */
        View::composer('admin.updates', function ($view) {
            $view->with([
                'adminPath' => AdminPathService::current(),
                'adminPathLocked' => AdminPathService::isLockedByEnv(),
            ]);
        });
    }
}
