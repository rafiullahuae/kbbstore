<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        // bootstrap/app.php pins the public path at the shared host's web root,
        // which exists on the server and nowhere else. CLAUDE.md says to leave
        // that line alone, so it stays; this overrides it for the test process
        // only. Without it, 2026_08_28_183000_relocate_public_assets sees
        // public/build as a stray copy of a directory that is somewhere else
        // entirely and DELETES it — which then shows up as public/build staged
        // for deletion in the next commit.
        $app->usePublicPath(dirname(__DIR__) . '/public');

        $app->make(Kernel::class)->bootstrap();

        // The lane's own route file, wired exactly as its header tells the
        // integrator to wire it into routes/web.php: inside auth:admin, inside
        // the /admin-api prefix, behind NoStoreAdminApi.
        //
        // Registered HERE, during application creation, rather than from a
        // test's setUp. A route added after the application has booted is not
        // matched by the first test in the process — the request 404s while
        // Route::getRoutes() still lists it — and the same test passes if it
        // happens to run second. Registering with the rest of the route table
        // removes the ordering dependency entirely, and is closer to what the
        // integrator will actually do.
        \Illuminate\Support\Facades\Route::middleware('auth:admin')->group(function () {
            \Illuminate\Support\Facades\Route::prefix('admin-api')
                ->middleware(\App\Http\Middleware\NoStoreAdminApi::class)
                ->group(function () {
                    require dirname(__DIR__) . '/routes/manual-orders-admin.php';
                });
        });

        return $app;
    }

}
