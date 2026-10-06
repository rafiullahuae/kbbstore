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
 * Mounts routes/mega-menu-picker-admin.php the way docs/mx-wiring.json tells
 * the integrator to: in the admin-api stack, and BEFORE the existing mega-menu
 * routes, whose unconstrained POST /mega-menu/{item} would otherwise answer
 * POST /mega-menu/pick with a 404 from model binding. (Lane MX)
 *
 * A no-op once routes/web.php carries the require, so the tests run against
 * the real mount after the integrator wires it.
 *
 * Built on Tests\Support\UgcAdminRoutes' notes — one middleware() call with
 * the whole stack, the kernel resolved first so `auth` is an alias, the
 * container reclaimed after the migration set's throwaway applications —
 * rather than extending it: that file is another lane's.
 */
final class MegaMenuPickerRoutes
{
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            if ($existing->uri() === 'admin-api/mega-menu/pick') {
                return;   // wired already: by routes/web.php, or an earlier case
            }
        }

        $old = $router->getRoutes()->getRoutes();

        // Ours first, then everything that was there, in its order.
        $router->setRoutes(new RouteCollection());
        RouteFacade::middleware(self::STACK)->prefix('admin-api')->group(base_path('routes/mega-menu-picker-admin.php'));

        foreach ($old as $route) {
            $router->getRoutes()->add($route);
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
