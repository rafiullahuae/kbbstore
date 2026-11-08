<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\NoStoreAdminApi;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Mounts routes/free-gifts-quick-admin.php (Lane GP) the way the integrator is
 * told to: inside the admin-api group, beside free-gifts-admin.php. A sibling
 * of Tests\Support\BannersAdminRoutes, same reasons; see that file.
 *
 * Idempotent, and a no-op once routes/web.php requires the file, so it stays
 * correct after the integrator wires it up.
 */
final class FreeGiftsQuickRoutes
{
    /** The stack routes/web.php's admin-api group applies (one middleware() call). */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        // The `auth` alias is put on the router by the kernel's constructor.
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            if ($existing->uri() === 'admin-api/free-gifts/quick') {
                return;
            }
        }

        $kept = new RouteCollection();
        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }
        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/free-gifts-quick-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
