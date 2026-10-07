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
 * Mounts routes/domain-switch-admin.php the way routes/web.php does once
 * tools/dw-wire.php has run (Lane DW), under the admin-api group's own stack.
 *
 * NOT an assertion that the file is unwired: DomainSwitchWiringTest pins the
 * FINISHED state (the require exactly once). This only registers what the
 * router does not already carry, so it behaves the same before and after the
 * integrator wires it -- the arrangement UgcAdminRoutes documents.
 */
final class DomainSwitchRoutes
{
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        // The kernel's constructor is what registers the `auth` alias on the router.
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            if ($existing->uri() === 'admin-api/domain-switch') {
                return;
            }
        }

        // A CompiledRouteCollection does not match routes added at run time.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)->prefix('admin-api')->group(base_path('routes/domain-switch-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
