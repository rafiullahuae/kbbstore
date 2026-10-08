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
 * Mounts routes/product-photo-admin.php (Lane RPL) the way the integrator is
 * told to: inside the same `web` + `auth:admin` + NoStoreAdminApi stack as the
 * rest of admin-api, so the capability tests dispatch through the real
 * EnforceAdminCapability before and after the require lands in web.php.
 * A copy of Tests\Support\ImageSeoAdminRoutes (Lane IR), whose notes on the
 * compiled route collection and the container apply here unchanged.
 */
final class ProductPhotoAdminRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first, or the guard is not what it appears.
         *
         * The `auth` middleware ALIAS is registered on the router by
         * Kernel::syncMiddlewareToRouter(), which runs in the kernel's
         * constructor and nowhere else. A test that never makes a real request
         * never builds the kernel, so 'auth' stays unresolved, the pipeline
         * resolves the AuthManager instead and the request dies with a 500 —
         * still a refusal, so a 401 assertion would be passing for the wrong
         * reason.
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

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/product-photo-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * See the long note in tests/Pest.php: the migration set runs config:cache
     * and route:cache, each of which constructs a throwaway Application and
     * points the container, the facade root and Eloquent's connection resolver
     * at it. Nothing puts them back, so anything reaching for app() afterwards
     * can be talking to a discarded application.
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
     * Every route this lane's file added, so a test can assert over all of them
     * rather than a list it has to remember to keep up to date.
     *
     * Filtered by CONTROLLER, not by URI: routes/web.php already registers
     * POST /admin-api/media/upload pointing at MediaUploadController, which is
     * another lane's file and is guarded by the same group for its own reasons.
     * Matching on the URI prefix would silently pull that route into this
     * lane's assertions and make them pass for somebody else's work.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->getAction('controller'), 'ProductEditorApiController@photoUndo'))
            ->values()
            ->all();
    }
}
