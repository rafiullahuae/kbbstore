<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AdminPathService;
use App\View\Composers\StoreComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
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
