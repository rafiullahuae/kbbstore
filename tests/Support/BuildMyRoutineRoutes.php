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
 * Mounts this lane's two route files the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so both files ship
 * with the require line in their own header. Nothing dispatches to either
 * controller until somebody wires them up, which leaves the gap Lane AX
 * describes in Tests\Support\MediaLibraryRoutes: a mistake in the guard would
 * not surface until a package shipped an endpoint that lists every product in
 * the catalogue — drafts and all, with their SKUs — to anyone who asks.
 *
 * ONE middleware() CALL PER GROUP, NOT TWO. RouteRegistrar::middleware()
 * REPLACES the pending middleware rather than appending to it, so
 *
 *     RouteFacade::middleware('web')->middleware('auth:admin')->group(...)
 *
 * registers routes carrying `auth:admin` and NOT `web` — or, with the order
 * reversed, no guard at all while still reading as though it had one. The whole
 * stack goes in one array below, and the tests read the middleware back off the
 * REGISTERED routes rather than trusting this file's intent.
 *
 * Deliberately a sibling of Tests\Support\MediaLibraryRoutes rather than a
 * shared base class: that file belongs to another lane and is not this one's to
 * change.
 */
final class BuildMyRoutineRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const ADMIN_STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    /** The storefront pages are registered at the top level of web.php. */
    public const STORE_STACK = ['web'];

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first, or the guard is not what it appears.
         * The `auth` middleware ALIAS is registered on the router by
         * Kernel::syncMiddlewareToRouter(), which runs in the kernel's
         * constructor and nowhere else — so without this 'auth' stays
         * unresolved, the pipeline resolves the AuthManager instead and the
         * request dies with a 500. Still a refusal, so a 401 assertion would be
         * passing for the wrong reason.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        // The router is serving a CompiledRouteCollection here — the migration
        // set runs route:cache — and routes added to one of those are not
        // matched. Copying into a plain RouteCollection first is what makes a
        // route registered at runtime actually dispatch.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::ADMIN_STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/build-my-routine-admin.php'));

        RouteFacade::middleware(self::STORE_STACK)
            ->group(base_path('routes/build-my-routine.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * See the long note in tests/Pest.php: the migration set runs config:cache
     * and route:cache, each of which constructs a throwaway Application and
     * points the container, the facade root and Eloquent's connection resolver
     * at it. Nothing puts them back.
     */
    private static function reclaimContainer(Application $app): void
    {
        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));
    }

    /**
     * Every route this lane's files added, found by CONTROLLER rather than by
     * URI: matching on a path prefix would pull another lane's routes into this
     * lane's assertions and make them pass for somebody else's work.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(function ($r) {
                $controller = (string) $r->getAction('controller');

                return str_contains($controller, 'RoutinesApiController')
                    || str_contains($controller, 'Store\RoutineController');
            })
            ->values()
            ->all();
    }
}
