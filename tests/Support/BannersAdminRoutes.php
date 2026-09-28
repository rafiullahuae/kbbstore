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
 * Mounts routes/banners-admin.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the twelve
 * routes it adds ship in their own file with the require line in that file's
 * header. Nothing dispatches to the controller until somebody else wires it up,
 * which would otherwise leave the capability guard on twelve endpoints that
 * rewrite the front page of the shop untested until a package shipped them.
 *
 * ▲ THIS IS THE SANCTIONED WAY TO TEST AN UNMOUNTED LANE'S ROUTES, and the
 * alternative is the one CLAUDE.md names as having cost this project three
 * round trips: an `expect($web)->not->toContain('banners-admin.php')` is green
 * in this worktree and goes RED the moment the integrator does the one thing
 * this lane asked for. CardsBannerAdminTest pins the FINISHED state instead —
 * substr_count(...) === 1 — which is green here today AND after the wiring.
 *
 * ONE middleware() CALL, NOT TWO — the trap Tests\Support\CustomersAdminRoutes
 * documents at length: RouteRegistrar::middleware() REPLACES the pending
 * middleware rather than appending to it, so chaining two calls registers
 * routes carrying only the second, and a guard test written against that
 * harness passes against nothing. The whole stack goes in one array.
 *
 * Deliberately a sibling of the other Tests\Support route harnesses rather than
 * a shared base class: those files belong to other lanes and are not this one's
 * to change.
 */
final class BannersAdminRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first, or the guard is not what it appears:
         * the `auth` middleware ALIAS is registered on the router by
         * Kernel::syncMiddlewareToRouter(), which runs in the kernel's
         * constructor and nowhere else. Without it the pipeline resolves the
         * AuthManager and the request dies with a 500 — still a refusal, so a
         * guard test would pass for the wrong reason.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            // Idempotent: several cases in one file call this, and registering
            // the same twelve routes twice makes the name lookup ambiguous.
            if ($existing->uri() === 'admin-api/banners') {
                return;
            }
        }

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
            ->group(base_path('routes/banners-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Every route this lane's file added, so a test can assert over all of them
     * rather than a list it has to remember to keep up to date.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => $r->uri() === 'admin-api/banners'
                || str_starts_with($r->uri(), 'admin-api/banners/'))
            ->values()
            ->all();
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
}
