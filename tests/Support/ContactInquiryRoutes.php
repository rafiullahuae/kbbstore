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
 * Registers Lane CT's two route files the way routes/web.php mounts them once
 * the integrator applies docs/ct-wiring.json: routes/contact-form.php in the
 * plain `web` group, routes/contact-inquiries-admin.php inside the guarded
 * admin-api group. A no-op when they are already there, so it is green before
 * and after wiring. The shape is NotFoundPageRoutes'.
 */
final class ContactInquiryRoutes
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
        $have = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $have[$route->uri()] = true;
        }

        if (isset($have['contact-us/send'], $have['admin-api/inquiries'])) {
            return;
        }

        // route:cache hands back a CompiledRouteCollection, which never matches
        // a route added later; copy into a plain collection first.
        $kept = new RouteCollection();
        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }
        $router->setRoutes($kept);

        if (! isset($have['contact-us/send'])) {
            RouteFacade::middleware('web')->group(base_path('routes/contact-form.php'));
        }

        if (! isset($have['admin-api/inquiries'])) {
            RouteFacade::middleware(self::STACK)->prefix('admin-api')->group(base_path('routes/contact-inquiries-admin.php'));
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
